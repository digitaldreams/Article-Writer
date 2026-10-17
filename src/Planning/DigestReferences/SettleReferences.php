<?php

namespace App\Planning\DigestReferences;

use App\Planning\Reference;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/** Finishes a reference: digest saved, or unreadable. Brainstorm's Next waits until none is pending. */
final readonly class SettleReferences
{
    public function __construct(private ManagerRegistry $doctrine)
    {
    }

    /** A failed extract, description or digest (no retry left) makes the reference unreadable; it never blocks the plan. */
    #[AsEventListener(priority: -10)]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$event->willRetry() && ($message instanceof ExtractReference || $message instanceof DigestReference)) {
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

        $reference = $em->find(Reference::class, $referenceId);
        if (null === $reference) {
            return;
        }
        null === $digest ? $reference->markUnreadable() : $reference->markDone($digest);
        $em->flush();
    }
}
