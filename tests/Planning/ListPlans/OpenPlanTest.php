<?php

namespace App\Tests\Planning\ListPlans;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OpenPlanTest extends WebTestCase
{
    /** @return iterable<string, array{list<ArticleState>, string}> */
    public static function steps(): iterable
    {
        yield 'plan' => [[], 'brainstorm'];
        yield 'questions' => [[ArticleState::Questions], 'questions'];
        yield 'flows' => [[ArticleState::Questions, ArticleState::Flows], 'flows'];
        yield 'outline' => [[ArticleState::Questions, ArticleState::Flows, ArticleState::Outline], 'outline'];
        yield 'finalize' => [[ArticleState::Questions, ArticleState::Flows, ArticleState::Outline, ArticleState::Finalize], 'review'];
        yield 'approved' => [[ArticleState::Questions, ArticleState::Flows, ArticleState::Outline, ArticleState::Finalize, ArticleState::Approved], 'article'];
    }

    /** @param list<ArticleState> $path */
    #[DataProvider('steps')]
    public function testOpeningAPlanRedirectsToItsCurrentStep(array $path, string $step): void
    {
        $client = static::createClient();
        $article = Article::plan('x', 'X');
        foreach ($path as $state) {
            $article->moveTo($state);
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($article);
        $em->flush();

        $client->request('GET', '/plans/'.$article->getId());
        self::assertResponseRedirects('/plans/'.$article->getId().'/'.$step);
    }

    public function testUnknownPlanIs404(): void
    {
        static::createClient()->request('GET', '/plans/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testImportedArticleIs404(): void
    {
        $client = static::createClient();
        $article = Article::imported('y', 'Y');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($article);
        $em->flush();

        $client->request('GET', '/plans/'.$article->getId());
        self::assertResponseStatusCodeSame(404);
    }
}
