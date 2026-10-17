<?php

namespace App\Planning\ListPlans;

use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Llm\WorkerHeartbeat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class StalledCheck
{
    /** The article job may run for a long time, so it is never "stalled". */
    private const ARTICLE_STEP = 'write_article';

    public function __construct(
        private EntityManagerInterface $em,
        private WorkerHeartbeat $heartbeat,
        private ClockInterface $clock,
    ) {
    }

    public function isStalled(): bool
    {
        $now = $this->clock->now();
        $jobs = $this->em->getRepository(LlmJob::class)->findBy(['status' => [LlmJobStatus::Working, LlmJobStatus::Queued]]);

        $anyWorking = false;
        $anyQueued = false;
        $anyStuck = false;
        foreach ($jobs as $job) {
            if (LlmJobStatus::Queued === $job->getStatus()) {
                $anyQueued = true;
                $anyStuck = $anyStuck || $job->isStuck($now);
                continue;
            }
            $anyWorking = true;
            $started = $job->getStartedAt();
            if (self::ARTICLE_STEP !== $job->getStep() && null !== $started && $started < $now->modify('-30 minutes')) {
                return true;
            }
        }

        $beat = $this->heartbeat->lastBeat();

        // Nothing working: a stuck job lost its message, or no worker is running.
        return $anyQueued && !$anyWorking && ($anyStuck || null === $beat || $beat < $now->modify('-2 minutes'));
    }
}
