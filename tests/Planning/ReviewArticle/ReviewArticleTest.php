<?php

namespace App\Tests\Planning\ReviewArticle;

use App\Planning\ReaderNote;
use App\Planning\ReviewArticle\ReaderReview;
use App\Planning\ReviewArticle\ReviewNote;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\ObjectResult;

final class ReviewArticleTest extends PlanningTestCase
{
    public function testTheReaderReviewsTheWholeArticleAndNotesShowUnderTheirSection(): void
    {
        $article = $this->writtenArticle();
        $prompt = '';
        $system = '';
        $this->fakePrimary(static function ($input) use (&$prompt, &$system) {
            $prompt = (string) $input->getUserMessage()?->asText();
            $system = (string) $input->getSystemMessage()?->getContent();

            return self::review(new ReviewNote('Setup', 'Why Composer?', 'Say why Composer is needed.'));
        });

        $this->client->request('POST', $this->url($article).'/review');
        self::assertResponseRedirects($this->url($article), 303);
        $this->consume();

        self::assertStringContainsString("- Setup\n- Usage", $prompt);
        self::assertStringContainsString('Call it.', $prompt);
        self::assertStringContainsString("- Audience: PHP developers\n- Level: intermediate", $system);
        $this->client->request('GET', $this->url($article));
        self::assertSelectorTextContains('[data-heading="Setup"] .reader-notes', 'Why Composer?');
        self::assertSelectorNotExists('[data-heading="Usage"] .reader-notes');
    }

    public function testANewReviewReplacesTheOldNotes(): void
    {
        $article = $this->writtenArticle();
        $round = 0;
        $this->fakePrimary(static function () use (&$round) {
            return self::review(new ReviewNote('Setup', 1 === ++$round ? 'First' : 'Second', 'Fix it.'));
        });

        $this->client->request('POST', $this->url($article).'/review');
        $this->consume();
        $this->client->request('POST', $this->url($article).'/review');
        $this->consume();

        $notes = $this->em()->getRepository(ReaderNote::class)->findBy(['article' => $article]);
        self::assertSame(['Second'], array_map(static fn (ReaderNote $n) => $n->getProblem(), $notes));
    }

    public function testANoteForAnUnknownHeadingIsStoredUnderGeneral(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => self::review(new ReviewNote('Conclusion', 'No ending.', 'Add a short ending.')));

        $this->client->request('POST', $this->url($article).'/review');
        $this->consume();

        self::assertSame(ReaderNote::GENERAL, $this->em()->getRepository(ReaderNote::class)->findOneBy(['article' => $article])?->getSection());
        $this->client->request('GET', $this->url($article));
        self::assertSelectorTextContains('.general-notes', 'No ending.');
    }

    public function testMoreThanEightNotesFailTheJobWithRetry(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => self::review(...array_fill(0, 9, new ReviewNote('Setup', 'Too much', 'Less'))));

        $this->client->request('POST', $this->url($article).'/review');
        $this->consume();

        self::assertSame(LlmJobStatus::Failed, $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'review_article'])?->getStatus());
        self::assertSame(0, $this->em()->getRepository(ReaderNote::class)->count(['article' => $article]));
        $this->client->request('GET', $this->url($article));
        self::assertSelectorExists('.reader-review .job-failed button');
    }

    public function testReviewBeforeTheArticleIsWrittenIsAConflict(): void
    {
        $article = $this->planIn(ArticleState::Approved);

        $this->client->request('POST', $this->url($article).'/review');

        self::assertResponseStatusCodeSame(409);
    }

    private function writtenArticle(): Article
    {
        $article = $this->planIn(ArticleState::Written);
        self::getContainer()->get(ArticleFolder::class)->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features'], "## Setup\n\nInstall it.\n\n## Usage\n\nCall it.\n");

        return $article;
    }

    private static function review(ReviewNote ...$notes): ObjectResult
    {
        return new ObjectResult(new ReaderReview(array_values($notes)));
    }

    private function url(Article $article): string
    {
        return '/plans/'.$article->getId().'/improve';
    }
}
