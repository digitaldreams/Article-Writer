<?php

namespace App\Tests\Planning\ListPlans;

use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\WorkerHeartbeat;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ListPlansTest extends WebTestCase
{
    use LogsInAuthor;

    public function testEmptyPlansPageIsShown(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->request('GET', '/plans');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plans');
        self::assertSelectorTextContains('body', 'No plans yet.');
    }

    public function testNewPlanOpensAModalThatLoadsTheTopicForm(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $crawler = $client->request('GET', '/plans');

        self::assertSame('new-plan-dialog', $crawler->filter('button[command="show-modal"]')->attr('commandfor'));
        self::assertSame('/plans/new', $crawler->filter('dialog#new-plan-dialog turbo-frame#new-plan')->attr('src'));

        $client->request('GET', '/plans/new');
        self::assertSelectorExists('turbo-frame#new-plan form[data-turbo-frame="_top"] input[name="create_plan[topic]"]');
        self::assertSelectorTextContains('turbo-frame#new-plan', 'first step of your article');
        self::assertSelectorExists('input[name="create_plan[topic]"][list="topic-options"]');
        self::assertCount(60, $client->getCrawler()->filter('turbo-frame#new-plan datalist#topic-options option'));
    }

    public function testSubmittedPlanAppearsWithItsState(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->disableReboot();
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Start brainstorming')->form(['create_plan[topic]' => 'Listed topic '.uniqid()]));

        $client->request('GET', '/plans');
        self::assertSelectorTextContains('table.plans', 'Listed topic');
        self::assertSelectorTextSame('tbody tr td:nth-child(2)', 'plan');
    }

    public function testMissingTopicShowsTheErrorInline(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Start brainstorming')->form(['create_plan[topic]' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form[name="create_plan"] ul li');
    }

    public function testFinishedJobIsFlaggedAndSortedFirstAndStalledJobWarns(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->disableReboot();
        self::getContainer()->get('cache.app')->deleteItem(WorkerHeartbeat::KEY);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $older = Article::plan($this->author, 's1', 'Has finished job');
        $newer = Article::plan($this->author, 's2', 'Has queued job');
        $em->persist($older);
        $em->persist($newer);
        $done = new LlmJob($older, 'ask_questions', 'primary', 'ollama', 'm');
        $done->markDone();
        $em->persist($done);
        $em->persist(new LlmJob($newer, 'ask_questions', 'primary', 'ollama', 'm'));
        $em->flush();

        $crawler = $client->request('GET', '/plans');

        self::assertSelectorTextContains('.notice-warning', 'not moving');
        self::assertStringContainsString('Has finished job', $crawler->filter('tbody tr')->first()->text());
        self::assertSame(1, $crawler->filter('tr.finished')->count());
    }
}
