<?php

namespace App\Tests\Shared\Llm\JobCard;

use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Tests\Shared\Llm\TestLlmMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JobCardTest extends WebTestCase
{
    public function testQueuedAndWorkingCardsPollEveryFiveSeconds(): void
    {
        $client = static::createClient();
        foreach ([LlmJobStatus::Queued, LlmJobStatus::Working] as $status) {
            $crawler = $client->request('GET', '/jobs/'.$this->job($status)->getId().'/card');

            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('turbo-frame > .job-card[data-controller="job-poll"][data-job-poll-interval-value="5000"]'));
            self::assertCount(0, $crawler->filter('button'));
        }
    }

    public function testCardResponseFrameHasNoSourcePointingAtItself(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/jobs/'.$this->job(LlmJobStatus::Working)->getId().'/card');

        self::assertCount(0, $crawler->filter('turbo-frame[src]'));
    }

    public function testDoneAndFailedCardsDoNotPoll(): void
    {
        $client = static::createClient();
        foreach ([LlmJobStatus::Done, LlmJobStatus::Failed] as $status) {
            $crawler = $client->request('GET', '/jobs/'.$this->job($status)->getId().'/card');

            self::assertCount(0, $crawler->filter('[data-controller]'));
        }
    }

    public function testFailedCardShowsErrorAndRetryRequeuesTheJob(): void
    {
        $client = static::createClient();
        $job = $this->job(LlmJobStatus::Failed);

        $crawler = $client->request('GET', '/jobs/'.$job->getId().'/card');
        self::assertStringContainsString('boom', $crawler->filter('.job-error')->text());
        $client->submit($crawler->selectButton('Retry')->form());

        self::assertResponseRedirects();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(LlmJobStatus::Queued, $em->find(LlmJob::class, $job->getId())->getStatus());
    }

    public function testCardShowsRoleProviderModelAndCloudNote(): void
    {
        $client = static::createClient();
        $local = $this->job(LlmJobStatus::Done);
        $cloud = $this->job(LlmJobStatus::Done, 'anthropic');

        $crawler = $client->request('GET', '/jobs/'.$local->getId().'/card');
        self::assertStringContainsString('primary · ollama · test-primary', $crawler->filter('.job-model')->text());
        self::assertCount(0, $crawler->filter('.job-cloud'));

        $crawler = $client->request('GET', '/jobs/'.$cloud->getId().'/card');
        self::assertStringContainsString('primary · anthropic · test-primary', $crawler->filter('.job-model')->text());
        self::assertStringContainsString('leaves this machine', $crawler->filter('.job-cloud')->text());
    }

    public function testUnknownJobIsNotFound(): void
    {
        static::createClient()->request('GET', '/jobs/999999/card');

        self::assertResponseStatusCodeSame(404);
    }

    private function job(LlmJobStatus $status, string $provider = 'ollama'): LlmJob
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $article = Article::plan('x', 'X');
        $job = new LlmJob($article, 'questions', 'primary', $provider, 'test-primary');
        $em->persist($article);
        $em->persist($job);
        $em->flush();
        $job->storeMessage(new TestLlmMessage((int) $job->getId()));
        match ($status) {
            LlmJobStatus::Failed => $job->markFailed('boom'),
            LlmJobStatus::Working => $job->markWorking(),
            LlmJobStatus::Done => $job->markDone(),
            default => null,
        };
        $em->flush();

        return $job;
    }
}
