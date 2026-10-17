<?php

namespace App\Tests\Shared\Llm\RetryJob;

use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Tests\LogsInAuthor;
use App\Tests\Shared\Llm\TestLlmMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RetryJobTest extends WebTestCase
{
    use LogsInAuthor;

    public function testRetryOnFailedJobDispatchesTheSameMessageAgain(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $job = $this->job(LlmJobStatus::Failed);

        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $client->request('POST', '/jobs/'.$job->getId().'/retry');

        self::assertResponseRedirects('/jobs/'.$job->getId().'/card', 303);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $job = $em->find(LlmJob::class, $job->getId());
        self::assertSame(LlmJobStatus::Queued, $job->getStatus());
        self::assertNull($job->getError());
        $sent = self::getContainer()->get('messenger.transport.llm_primary');
        self::assertInstanceOf(InMemoryTransport::class, $sent);
        self::assertCount(1, $sent->getSent());
        $message = $sent->getSent()[0]->getMessage();
        self::assertInstanceOf(TestLlmMessage::class, $message);
        self::assertSame($job->getId(), $message->llmJobId());
    }

    public function testRetryOnNonFailedJobIsRefused(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $job = $this->job(LlmJobStatus::Working);

        $client->request('POST', '/jobs/'.$job->getId().'/retry');

        self::assertResponseStatusCodeSame(409);
        $sent = self::getContainer()->get('messenger.transport.llm_primary');
        self::assertInstanceOf(InMemoryTransport::class, $sent);
        self::assertCount(0, $sent->getSent());
    }

    public function testRetryOfUnknownJobIsNotFound(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->request('POST', '/jobs/999999/retry');

        self::assertResponseStatusCodeSame(404);
    }

    private function job(LlmJobStatus $status): LlmJob
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $article = Article::plan($this->author, 'x', 'X');
        $job = new LlmJob($article, 'questions', 'primary', 'ollama', 'test-primary');
        $em->persist($article);
        $em->persist($job);
        $em->flush();
        $job->storeMessage(new TestLlmMessage((int) $job->getId()));
        match ($status) {
            LlmJobStatus::Failed => $job->markFailed('boom'),
            LlmJobStatus::Working => $job->markWorking(),
            default => null,
        };
        $em->flush();

        return $job;
    }
}
