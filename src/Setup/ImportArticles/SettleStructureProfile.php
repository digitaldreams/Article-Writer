<?php

namespace App\Setup\ImportArticles;

use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobMessage;
use App\Shared\Llm\LlmJobs;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\User\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * After every note or import of an author has finished (done or failed for good), starts one merge when nothing of theirs is pending.
 * Runs after TrackLlmJob (priority -10), so the finished job already counts as done or failed.
 */
final readonly class SettleStructureProfile
{
    private const STEPS = ['import_file', 'extract_structure_notes', 'merge_structure_profile'];

    public function __construct(private ManagerRegistry $doctrine, private LlmJobs $jobs)
    {
    }

    #[AsEventListener(priority: -10)]
    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($message instanceof ExtractStructureNotes) {
            $this->settle($this->userIdOf($message));
        }
    }

    #[AsEventListener(priority: -10)]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$event->willRetry() && ($message instanceof ExtractStructureNotes || $message instanceof ImportFile)) {
            $this->settle($this->userIdOf($message));
        }
    }

    /** Takes the id: a failed job may have closed the entity manager, so the user is loaded again from a fresh one. */
    public function settle(int $userId): void
    {
        $em = $this->em();
        $em->wrapInTransaction(function () use ($em, $userId): void {
            // The user row lock makes concurrent settle calls take turns; the second one sees the merge job of the first.
            $user = $em->find(User::class, $userId, LockMode::PESSIMISTIC_WRITE) ?? throw new \RuntimeException('The user is gone.');

            $pending = (int) $em->getRepository(LlmJob::class)->createQueryBuilder('j')
                ->select('COUNT(j.id)')
                ->where('j.step IN (:steps)')->andWhere('j.status IN (:open)')->andWhere('j.user = :user')
                ->setParameter('steps', self::STEPS)
                ->setParameter('open', [LlmJobStatus::Queued, LlmJobStatus::Working])
                ->setParameter('user', $user)
                ->getQuery()->getSingleScalarResult();

            if (0 === $pending && $em->getRepository(StructureNote::class)->count(['user' => $user]) > 0) {
                $this->jobs->start(null, 'merge_structure_profile', 'secondary', static fn (int $id) => new MergeStructureProfile($id), $user);
            }
        });
    }

    private function userIdOf(LlmJobMessage $message): int
    {
        $job = $this->em()->find(LlmJob::class, $message->llmJobId()) ?? throw new \RuntimeException('The job is gone.');

        return (int) $job->getUser()->getId();
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->doctrine->getManager();
        if ($em instanceof EntityManagerInterface && !$em->isOpen()) {
            $em = $this->doctrine->resetManager();
        }
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
