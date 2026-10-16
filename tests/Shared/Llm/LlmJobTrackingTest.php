<?php

namespace App\Tests\Shared\Llm;

use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Llm\WorkerHeartbeat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class LlmJobTrackingTest extends KernelTestCase
{
    public function testJobMovesFromQueuedToWorkingToDone(): void
    {
        $job = $this->queuedJob();
        self::assertSame(LlmJobStatus::Queued, $job->getStatus());

        $seenWhileHandling = null;
        $this->consume(function (TestLlmMessage $m) use (&$seenWhileHandling): void {
            $seenWhileHandling = $this->em()->getConnection()->fetchOne('SELECT status FROM llm_job WHERE id = ?', [$m->llmJobId()]);
        });

        self::assertSame('working', $seenWhileHandling);
        $job = $this->em()->find(LlmJob::class, $job->getId());
        self::assertSame(LlmJobStatus::Done, $job->getStatus());
        self::assertNotNull($job->getFinishedAt());
    }

    public function testFailingHandlerEndsAsFailedWithTheError(): void
    {
        $job = $this->queuedJob();

        $this->consume(static fn () => throw new \RuntimeException('Model answered with broken JSON'));

        $job = $this->em()->find(LlmJob::class, $job->getId());
        self::assertSame(LlmJobStatus::Failed, $job->getStatus());
        self::assertSame('Model answered with broken JSON', $job->getError());
    }

    public function testMessageWithoutLlmJobIsIgnored(): void
    {
        $handled = 0;
        $this->consume(static function () use (&$handled): void {
            ++$handled;
        }, new \stdClass());

        self::assertSame(1, $handled);
    }

    public function testWorkerTickWritesHeartbeat(): void
    {
        self::bootKernel();
        $heartbeat = self::getContainer()->get(WorkerHeartbeat::class);
        self::getContainer()->get('cache.app')->deleteItem(WorkerHeartbeat::KEY);
        self::assertNull($heartbeat->lastBeat());

        $worker = new Worker([], new MessageBus(), self::getContainer()->get(EventDispatcherInterface::class));
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new WorkerRunningEvent($worker, true));

        self::assertNotNull($heartbeat->lastBeat());
    }

    private function queuedJob(): LlmJob
    {
        self::bootKernel();
        $em = $this->em();
        $article = Article::plan('x', 'X');
        $job = new LlmJob($article, 'questions', 'primary', 'ollama', 'test-primary');
        $em->persist($article);
        $em->persist($job);
        $em->flush();
        $job->storeMessage($message = new TestLlmMessage((int) $job->getId()));
        $em->flush();
        self::getContainer()->get('messenger.default_bus')->dispatch($message);

        return $job;
    }

    private function consume(callable $handler, ?object $other = null): void
    {
        $transport = self::getContainer()->get('messenger.transport.llm_primary');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        if (null !== $other) {
            $transport->send(new Envelope($other));
        }

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            TestLlmMessage::class => [$handler],
            \stdClass::class => [$handler],
        ]))]);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $worker = new Worker(['llm_primary' => $transport], $bus, $dispatcher);
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $e) use ($worker): void {
            if ($e->isWorkerIdle()) {
                $worker->stop();
            }
        });
        $worker->run(['sleep' => 0]);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
