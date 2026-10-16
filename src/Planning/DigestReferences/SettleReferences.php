<?php

namespace App\Planning\DigestReferences;

use App\Planning\Reference;
use App\Planning\ReferencesSettled;
use App\Planning\ReferenceStatus;
use App\Shared\Article\ArticleState;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Finishes a reference (digest saved, or unreadable) and fires ReferencesSettled once the last one is finished.
 * The status change and the check share one transaction under the article row lock,
 * so two references that finish together can't both see "none pending".
 */
final readonly class SettleReferences
{
    public function __construct(private ManagerRegistry $doctrine, private EventDispatcherInterface $events)
    {
    }

    /** A failed extract, description or digest (no retry left) makes the reference unreadable; it never blocks the plan. */
    #[AsEventListener(priority: -10)]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$event->willRetry() && ($message instanceof ExtractReference || $message instanceof DescribeImage || $message instanceof DigestReference)) {
            $this->finish($message->referenceId, null);
        }
    }

    /** @param ?string $digest null marks the reference unreadable */
    public function finish(int $referenceId, ?string $digest): void
    {
        $em = $this->doctrine->getManager();
        if ($em instanceof EntityManagerInterface && !$em->isOpen()) {
            $em = $this->doctrine->resetManager();
        }
        \assert($em instanceof EntityManagerInterface);

        $em->wrapInTransaction(function () use ($em, $referenceId, $digest): void {
            $reference = $em->find(Reference::class, $referenceId);
            if (null === $reference) {
                return;
            }
            $article = $reference->getArticle();
            $em->lock($article, LockMode::PESSIMISTIC_WRITE);

            null === $digest ? $reference->markUnreadable() : $reference->markDone($digest);
            $em->flush();

            $pending = $em->getRepository(Reference::class)->count(['article' => $article, 'status' => ReferenceStatus::Pending]);
            if (0 === $pending && ArticleState::Plan === $article->getState()) {
                $this->events->dispatch(new ReferencesSettled((int) $article->getId()));
            }
        });
    }
}
