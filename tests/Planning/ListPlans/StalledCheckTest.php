<?php

namespace App\Tests\Planning\ListPlans;

use App\Planning\ListPlans\StalledCheck;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\WorkerHeartbeat;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StalledCheckTest extends KernelTestCase
{
    use LogsInAuthor;

    public function testJobWorkingForMoreThan30MinutesIsStalled(): void
    {
        $this->heartbeat(0);
        $this->job('ask_questions', working: true);

        self::assertTrue($this->check('+31 minutes')->isStalled());
    }

    public function testJobWorkingForLessThan30MinutesIsNotStalled(): void
    {
        $this->heartbeat(0);
        $this->job('ask_questions', working: true);

        self::assertFalse($this->check('+29 minutes')->isStalled());
    }

    public function testArticleJobWorkingForAnHourIsNotStalled(): void
    {
        $this->heartbeat(0);
        $this->job('write_article', working: true);

        self::assertFalse($this->check('+60 minutes')->isStalled());
    }

    public function testQueuedJobWithStaleHeartbeatIsStalled(): void
    {
        $this->heartbeat(-180);
        $this->job('ask_questions');

        self::assertTrue($this->check()->isStalled());
    }

    public function testQueuedJobWithoutAnyHeartbeatIsStalled(): void
    {
        $this->heartbeat(null);
        $this->job('ask_questions');

        self::assertTrue($this->check()->isStalled());
    }

    public function testQueuedJobWithFreshHeartbeatIsNotStalled(): void
    {
        $this->heartbeat(-30);
        $this->job('ask_questions');

        self::assertFalse($this->check()->isStalled());
    }

    public function testQueuedJobBehindAWorkingJobIsNotStalled(): void
    {
        $this->heartbeat(-600);
        $this->job('ask_questions', working: true);
        $this->job('plan_title');

        self::assertFalse($this->check()->isStalled());
    }

    public function testJobQueuedOver10MinutesIsStalledEvenWithAFreshHeartbeat(): void
    {
        $this->heartbeat(11 * 60 - 30);
        $this->job('suggest_punchlines');

        self::assertTrue($this->check('+11 minutes')->isStalled());
    }

    public function testJobQueuedOver10MinutesBehindAWorkingJobIsNotStalled(): void
    {
        $this->heartbeat(11 * 60 - 30);
        $this->job('write_article', working: true);
        $this->job('suggest_punchlines');

        self::assertFalse($this->check('+11 minutes')->isStalled());
    }

    public function testNothingRunningIsNotStalled(): void
    {
        $this->heartbeat(null);

        self::assertFalse($this->check()->isStalled());
    }

    private function check(string $now = 'now'): StalledCheck
    {
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);

        return new StalledCheck(self::getContainer()->get(EntityManagerInterface::class), new WorkerHeartbeat($cache), new MockClock($now));
    }

    private function heartbeat(?int $secondsFromNow): void
    {
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->deleteItem(WorkerHeartbeat::KEY);
        if (null !== $secondsFromNow) {
            $cache->save($cache->getItem(WorkerHeartbeat::KEY)->set(time() + $secondsFromNow));
        }
    }

    private function job(string $step, bool $working = false): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $article = Article::plan(self::newAuthor(), 'z', 'Z');
        $job = new LlmJob($article, $step, 'primary', 'ollama', 'm');
        if ($working) {
            $job->markWorking();
        }
        $em->persist($article);
        $em->persist($job);
        $em->flush();
    }
}
