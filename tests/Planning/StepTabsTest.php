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

        foreach (['brainstorm' => 'Brainstorming', 'questions' => 'Questions', 'outline' => 'Outline', 'article' => 'Article', 'improve' => 'Improve', 'titles' => 'Titles', 'poster' => 'Poster', 'share' => 'Share'] as $step => $label) {
            $this->client->request('GET', '/plans/'.$article->getId().'/'.$step);

            self::assertResponseIsSuccessful($step);
            self::assertSelectorCount(8, 'nav.steps a');
            self::assertSelectorTextSame('nav.steps a[aria-current=page]', $label);
        }
    }

    public function testStepsNotReachedYetAreNotLinks(): void
    {
        $article = $this->planIn(ArticleState::Outline);

        $this->client->request('GET', '/plans/'.$article->getId().'/outline');

        self::assertSelectorCount(3, 'nav.steps a');
        self::assertSelectorCount(5, 'nav.steps span[aria-disabled=true]');
        self::assertSelectorTextSame('nav.steps li:last-child span', 'Share');
    }
}
