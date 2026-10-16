<?php

namespace App\Shared\Llm;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/** Writes "the worker is alive" on every worker tick. The stalled warning reads it. */
final class WorkerHeartbeat
{
    public const KEY = 'llm_worker_heartbeat';

    public function __construct(#[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache)
    {
    }

    #[AsEventListener]
    public function beat(WorkerRunningEvent $event): void
    {
        $this->cache->save($this->cache->getItem(self::KEY)->set(time()));
    }

    public function lastBeat(): ?\DateTimeImmutable
    {
        $item = $this->cache->getItem(self::KEY);

        return $item->isHit() ? (new \DateTimeImmutable())->setTimestamp($item->get()) : null;
    }
}
