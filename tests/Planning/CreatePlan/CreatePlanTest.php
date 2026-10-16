<?php

namespace App\Tests\Planning\CreatePlan;

use App\Planning\PlanInput;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreatePlanTest extends WebTestCase
{
    public function testTopicAndIdeaAreSavedInStatePlanAndOpenBrainstorming(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/plans/new');
        self::assertResponseIsSuccessful();
        self::assertSame(['create_plan[topic]', 'create_plan[idea]'], $crawler->filter('form [name^="create_plan["]:not([type=submit]):not([type=hidden])')->each(static fn ($n) => $n->attr('name')));
        $client->submit($crawler->selectButton('Create')->form([
            'create_plan[topic]' => 'Symfony AI Agents '.uniqid(),
            'create_plan[idea]' => 'Hooks replace getters',
        ]));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $article = $em->getRepository(Article::class)->findOneBy([]);
        self::assertInstanceOf(Article::class, $article);
        self::assertResponseRedirects('/plans/'.$article->getId().'/brainstorm');
        self::assertSame(ArticleState::Plan, $article->getState());
        self::assertSame('Hooks replace getters', $em->getRepository(PlanInput::class)->findOneBy(['article' => $article])?->getRoughFlow());
        self::assertSame(['generate_plan_title'], array_map(static fn (LlmJob $j) => $j->getStep(), $em->getRepository(LlmJob::class)->findBy(['article' => $article])));
    }

    public function testMissingTopicGives422AndSavesNothing(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Create')->form(['create_plan[topic]' => '']));

        self::assertResponseStatusCodeSame(422);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame([], $em->getRepository(Article::class)->findAll());
        self::assertSame([], $em->getRepository(PlanInput::class)->findAll());
    }
}
