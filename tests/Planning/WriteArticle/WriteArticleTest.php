<?php

namespace App\Tests\Planning\WriteArticle;

use App\Planning\PlanApproved;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class WriteArticleTest extends PlanningTestCase
{
    public function testAHandEditToOutlineMdIsInThePromptAndTheArticleIsWritten(): void
    {
        $article = $this->approvedPlan();
        $this->folder()->write($article->getFolder(), 'outline.md', ['plan_id' => $article->getId()], "## Edited by hand\n\nMy own words.\n");
        $prompt = '';
        $system = '';
        $this->fakePrimary(static function ($input) use (&$prompt, &$system) {
            $prompt = (string) $input->getUserMessage()?->asText();
            $system = (string) $input->getSystemMessage()?->getContent();

            return new TextResult("## Edited by hand\n\nBody <script>alert(1)</script>");
        });

        $this->approve($article);
        $this->consume();

        self::assertStringContainsString('## Edited by hand', $prompt);
        self::assertStringContainsString('Headings, strictly: every section heading is "## " and a part inside a section is "### ".', $system);
        self::assertSame(ArticleState::Written, $this->reload($article)->getState());
        $written = $this->folder()->read($article->getFolder(), 'article.md');
        self::assertSame(['plan_id' => $article->getId(), 'title' => 'PHP 8 features'], $written['frontMatter']);

        $this->client->request('GET', '/plans/'.$article->getId().'/article');
        self::assertSelectorTextContains('.article-preview h2', 'Edited by hand');
        self::assertSelectorNotExists('.article-preview script');
        self::assertSelectorTextContains('.notice code', 'article.md');
        self::assertSelectorTextContains('[data-action="medium-copy#copy"]', 'Copy for Medium');
    }

    public function testTheArticleRequestHasTheVoiceWhenSetAndTheStructureWithoutIt(): void
    {
        $this->author->setWritingProfile(['openingType' => 'a real bug story'], ['tone' => 'dry humor', 'codeHabit' => '', 'samples' => 'A verbatim paragraph.']);
        $article = $this->approvedPlan();
        $prompt = $this->articlePrompt($article);

        self::assertStringContainsString("# The writer's usual structure\n- openingType: a real bug story\n\n# Your voice\n- tone: dry humor\n", $prompt);
        self::assertStringNotContainsString('codeHabit', $prompt);
        self::assertStringContainsString('A verbatim paragraph.', $prompt);
    }

    public function testTheArticleRequestHasNoVoiceWhenItIsEmpty(): void
    {
        $this->author->setWritingProfile(['openingType' => 'a real bug story'], ['tone' => '', 'samples' => '']);
        $article = $this->approvedPlan();

        self::assertStringNotContainsString('# Your voice', $this->articlePrompt($article));
    }

    private function articlePrompt(Article $article): string
    {
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return new TextResult("## Body\n\nText.");
        });
        $this->approve($article);
        $this->consume();

        return $prompt;
    }

    public function testAFailedJobKeepsApprovedWithRetry(): void
    {
        $article = $this->approvedPlan();
        $this->fakePrimary(static fn () => throw new \RuntimeException('model down'));

        $this->approve($article);
        $this->consume();

        self::assertSame(ArticleState::Approved, $this->reload($article)->getState());
        self::assertSame(LlmJobStatus::Failed, $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'write_article'])?->getStatus());
        $this->client->request('GET', '/plans/'.$article->getId().'/article');
        self::assertSelectorExists('.job-failed button');
        self::assertFileDoesNotExist($this->folder()->pathOf($article->getFolder(), 'article.md'));
    }

    public function testAReplayOverwritesArticleMd(): void
    {
        $article = $this->approvedPlan();
        $round = 0;
        $this->fakePrimary(static function () use (&$round) {
            return new TextResult('Version '.++$round);
        });
        $this->approve($article);
        $this->consume();

        self::getContainer()->get('messenger.bus.default')->dispatch(
            $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'write_article'])?->storedMessage() ?? throw new \LogicException(),
        );
        $this->consume();

        self::assertSame("Version 2\n", $this->folder()->read($article->getFolder(), 'article.md')['body']);
    }

    private function approvedPlan(): Article
    {
        $article = $this->planIn(ArticleState::Approved);
        $this->folder()->write($article->getFolder(), 'idea.md', ['plan_id' => $article->getId()], "# PHP 8 features\n");
        $this->folder()->write($article->getFolder(), 'outline.md', ['plan_id' => $article->getId()], "## The old pain\n\nUntyped.\n");

        return $article;
    }

    private function approve(Article $article): void
    {
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new PlanApproved((int) $article->getId()));
        $this->em()->flush();
    }

    private function folder(): ArticleFolder
    {
        return self::getContainer()->get(ArticleFolder::class);
    }
}
