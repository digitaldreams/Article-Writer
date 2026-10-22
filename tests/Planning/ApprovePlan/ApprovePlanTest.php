<?php

namespace App\Tests\Planning\ApprovePlan;

use App\Planning\OutlineItem;
use App\Planning\PlanApproved;
use App\Planning\Question;
use App\Planning\Reference;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;

final class ApprovePlanTest extends PlanningTestCase
{
    public function testApproveWritesThePlanFilesInTheRequiredFormatAndLocksThePlan(): void
    {
        $article = $this->outlinedPlan(answered: true);
        $fired = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(PlanApproved::class, static function () use (&$fired): void {
            $fired[] = true;
        });

        $this->client->request('POST', '/plans/'.$article->getId().'/approve');

        self::assertResponseRedirects('/plans/'.$article->getId());
        self::assertSame(ArticleState::Approved, $this->reload($article)->getState());
        self::assertSame([true], $fired);

        $folder = self::getContainer()->get(ArticleFolder::class);
        $idea = $folder->read($article->getFolder(), 'idea.md');
        self::assertSame($article->getId(), $idea['frontMatter']['plan_id']);
        self::assertArrayHasKey('created_at', $idea['frontMatter']);
        self::assertStringContainsString("## Rough flow\n\nTyped properties, hooks", $idea['body']);
        self::assertStringContainsString("- https://php.net/8.4\n- https://symfony.com/doc", $idea['body']);

        $outline = $folder->read($article->getFolder(), 'outline.md');
        self::assertSame(['plan_id', 'title', 'approved_at'], array_keys($outline['frontMatter']));
        self::assertSame("## The old pain\n\nUntyped properties.\n\n## Typed properties\n\nWhat they fix.\n", $outline['body']);

        $answers = $folder->read($article->getFolder(), 'answers.md');
        self::assertSame(['plan_id' => $article->getId(), 'answered' => 1, 'total' => 2], $answers['frontMatter']);
        self::assertSame("## Which problem does it solve?\n\nBoilerplate.\n", $answers['body']);
    }

    public function testNoAnswersMeansNoAnswersFile(): void
    {
        $article = $this->outlinedPlan(answered: false);

        $this->client->request('POST', '/plans/'.$article->getId().'/approve');

        self::assertFileDoesNotExist(self::getContainer()->get(ArticleFolder::class)->pathOf($article->getFolder(), 'answers.md'));
    }

    public function testAFailedFileWriteCommitsNothing(): void
    {
        $article = $this->outlinedPlan(answered: false);
        $folder = self::getContainer()->get(ArticleFolder::class);
        (new Filesystem())->mkdir($folder->pathOf($article->getFolder(), 'outline.md'));

        $this->client->request('POST', '/plans/'.$article->getId().'/approve');

        self::assertResponseStatusCodeSame(500);
        self::assertSelectorTextContains('.field-error', 'The plan files could not be written');
        self::assertSame(ArticleState::Outline, $this->reload($article)->getState());
    }

    public function testTheOutlinePageOffersApprove(): void
    {
        $article = $this->outlinedPlan(answered: false);

        $this->client->request('GET', '/plans/'.$article->getId().'/outline');

        self::assertSelectorTextContains('form[action$="/approve"] button.button-primary', 'Approve and write the article');
    }

    public function testApproveAnEmptyOutlineIsAConflict(): void
    {
        $article = $this->planIn(ArticleState::Outline);

        $this->client->request('POST', '/plans/'.$article->getId().'/approve');

        self::assertResponseStatusCodeSame(409);
        self::assertSame(ArticleState::Outline, $this->reload($article)->getState());
    }

    public function testApproveWhileAnOutlineRoundIsGeneratingIsAConflict(): void
    {
        $article = $this->outlinedPlan(answered: false);
        $this->client->request('POST', '/plans/'.$article->getId().'/outline/direction', ['instruction' => 'Shorter']);

        $this->client->request('POST', '/plans/'.$article->getId().'/approve');

        self::assertResponseStatusCodeSame(409);
        self::assertSame(ArticleState::Outline, $this->reload($article)->getState());
    }

    public function testApproveFromAnyOtherStateIsAConflict(): void
    {
        $article = $this->planIn(ArticleState::Questions);

        $this->client->request('POST', '/plans/'.$article->getId().'/approve');

        self::assertResponseStatusCodeSame(409);
    }

    private function outlinedPlan(bool $answered): Article
    {
        $article = $this->planIn(ArticleState::Outline);
        (new Filesystem())->mkdir(self::getContainer()->get(ArticleFolder::class)->pathOf($article->getFolder(), ''));
        $question = new Question($article, 'Which problem does it solve?', 0);
        if ($answered) {
            $question->saveAnswer('Boilerplate.');
        }
        foreach ([
            new OutlineItem($article, 1, 0, 'The old pain', 'Untyped properties.'),
            new OutlineItem($article, 1, 1, 'Typed properties', 'What they fix.'),
            $question,
            new Question($article, 'Why should I care?', 1),
            new Reference($article, 'https://php.net/8.4'),
            new Reference($article, 'https://symfony.com/doc'),
        ] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return $article;
    }
}
