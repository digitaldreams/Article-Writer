<?php

namespace App\Tests\Planning\CreatePlan;

use App\Planning\PlanInput;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreatePlanTest extends WebTestCase
{
    use LogsInAuthor;

    public function testTopicIsSavedInStatePlanAndOpensBrainstorming(): void
    {
        $client = static::createClient();
        $this->logIn($client);

        $crawler = $client->request('GET', '/plans/new');
        self::assertResponseIsSuccessful();
        self::assertSame(['create_plan[topic]'], $crawler->filter('form [name^="create_plan["]:not([type=submit]):not([type=hidden])')->each(static fn ($n) => $n->attr('name')));
        $client->submit($crawler->selectButton('Start brainstorming')->form(['create_plan[topic]' => 'Symfony AI Agents '.uniqid()]));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $article = $em->getRepository(Article::class)->findOneBy([]);
        self::assertInstanceOf(Article::class, $article);
        self::assertResponseRedirects('/plans/'.$article->getId().'/brainstorm');
        self::assertSame(ArticleState::Plan, $article->getState());
        self::assertSame('', $em->getRepository(PlanInput::class)->findOneBy(['article' => $article])?->getRoughFlow());
        self::assertSame(['generate_plan_title'], array_map(static fn (LlmJob $j) => $j->getStep(), $em->getRepository(LlmJob::class)->findBy(['article' => $article])));
    }

    public function testFormPostsToNewPlanFromInsideThePlansPageModal(): void
    {
        $client = static::createClient();
        $this->logIn($client);

        $crawler = $client->request('GET', '/plans/new');

        self::assertSame('/plans/new', $crawler->filter('turbo-frame#new-plan form')->attr('action'));
    }

    public function testMissingTopicGives422AndSavesNothing(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Start brainstorming')->form(['create_plan[topic]' => '']));

        self::assertResponseStatusCodeSame(422);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame([], $em->getRepository(Article::class)->findAll());
        self::assertSame([], $em->getRepository(PlanInput::class)->findAll());
    }
}
