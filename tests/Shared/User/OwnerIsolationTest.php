<?php

namespace App\Tests\Shared\User;

use App\Planning\PlanContext;
use App\Planning\PlanInput;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OwnerIsolationTest extends WebTestCase
{
    use LogsInAuthor;

    public function testAuthorCannotOpenAnotherAuthorsPlan(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $theirs = $this->plan(Article::plan(self::newAuthor(), 'theirs', 'Their plan'));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $client->request('GET', '/plans/'.$theirs->getId().'/brainstorm');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPlansListShowsOnlyTheAuthorsOwnPlans(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->plan(Article::plan($this->author, 'mine', 'My plan'));
        $this->plan(Article::plan(self::newAuthor(), 'theirs', 'Their plan'));

        $client->request('GET', '/plans');

        self::assertSelectorTextContains('table.plans', 'My plan');
        self::assertSelectorTextNotContains('table.plans', 'Their plan');
    }

    public function testAuthorCannotSeeOrRetryAnotherAuthorsJob(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $job = new LlmJob($this->plan(Article::plan(self::newAuthor(), 'theirs', 'Their plan')), 'questions', 'primary', 'ollama', 'm');
        $job->markFailed('model down');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($job);
        $em->flush();
        $em->clear();

        $client->request('GET', '/jobs/'.$job->getId().'/card');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/jobs/'.$job->getId().'/retry');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(LlmJobStatus::Failed, $em->getRepository(LlmJob::class)->find($job->getId())?->getStatus());
    }

    public function testWorkerContextUsesTheProfileOfThePlansAuthor(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::newAuthor()->updateProfile('', '', 'native');
        $author = self::newAuthor();
        $author->updateProfile('', '', 'intermediate');
        $article = $this->plan(Article::plan($author, 'mine', 'My plan'));

        self::assertSame('intermediate', self::getContainer()->get(PlanContext::class)->of($article)['language_level']);
    }

    private function plan(Article $article): Article
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($article);
        $em->persist(new PlanInput($article, 'topic', 'devs', null, 'flow'));
        $em->flush();

        return $article;
    }
}
