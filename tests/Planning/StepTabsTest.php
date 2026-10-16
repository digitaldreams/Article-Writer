<?php

namespace App\Tests\Planning;

use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;

final class StepTabsTest extends PlanningTestCase
{
    public function testEveryStepIsATabOnItsOwnPage(): void
    {
        $article = $this->planIn(ArticleState::Written);
        self::getContainer()->get(ArticleFolder::class)->write($article->getFolder(), 'article.md', ['title' => 'PHP 8 features'], "# PHP 8 features\n");

        foreach (['questions' => 'Questions', 'flows' => 'Flows', 'outline' => 'Outline', 'review' => 'Review', 'article' => 'Article', 'titles' => 'Titles', 'poster' => 'Poster'] as $step => $label) {
            $this->client->request('GET', '/plans/'.$article->getId().'/'.$step);

            self::assertResponseIsSuccessful($step);
            self::assertSelectorCount(7, 'nav.steps a');
            self::assertSelectorTextSame('nav.steps a[aria-current=page]', $label);
        }
    }

    public function testStepsNotReachedYetAreNotLinks(): void
    {
        $article = $this->planIn(ArticleState::Flows);

        $this->client->request('GET', '/plans/'.$article->getId().'/flows');

        self::assertSelectorCount(2, 'nav.steps a');
        self::assertSelectorCount(5, 'nav.steps span[aria-disabled=true]');
        self::assertSelectorTextSame('nav.steps li:last-child span', 'Poster');
    }
}
