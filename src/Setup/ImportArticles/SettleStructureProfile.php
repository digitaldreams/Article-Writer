<?php

namespace App\Setup\ImportArticles;

use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobs;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\WriterProfile\WriterProfile;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * After every note or import has finished (done or failed for good), starts one merge when nothing is pending.
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
        if ($event->getEnvelope()->getMessage() instanceof ExtractStructureNotes) {
            $this->settle();
        }
    }

    #[AsEventListener(priority: -10)]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$event->willRetry() && ($message instanceof ExtractStructureNotes || $message instanceof ImportFile)) {
            $this->settle();
        }
    }

    public function settle(): void
    {
        $em = $this->doctrine->getManager();
        if ($em instanceof EntityManagerInterface && !$em->isOpen()) {
            $em = $this->doctrine->resetManager();
        }
        \assert($em instanceof EntityManagerInterface);

        $em->wrapInTransaction(function () use ($em): void {
            // The row lock makes concurrent settle calls take turns; the second one sees the merge job of the first.
            $profile = $em->getRepository(WriterProfile::class)->findOneBy([]);
            if (null === $profile) {
                return;
            }
            $em->lock($profile, LockMode::PESSIMISTIC_WRITE);

            $pending = (int) $em->getRepository(LlmJob::class)->createQueryBuilder('j')
                ->select('COUNT(j.id)')
                ->where('j.step IN (:steps)')->andWhere('j.status IN (:open)')
                ->setParameter('steps', self::STEPS)
                ->setParameter('open', [LlmJobStatus::Queued, LlmJobStatus::Working])
                ->getQuery()->getSingleScalarResult();

            if (0 === $pending && $em->getRepository(StructureNote::class)->count([]) > 0) {
                $this->jobs->start(null, 'merge_structure_profile', 'secondary', static fn (int $id) => new MergeStructureProfile($id));
            }
        });
    }
}
