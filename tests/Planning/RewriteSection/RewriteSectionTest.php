<?php

namespace App\Tests\Planning\RewriteSection;

use App\Planning\ReaderNote;
use App\Planning\RewriteSection\SectionRewrite;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Storage\ArticleFolder;
use App\Shared\User\User;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\TextResult;

final class RewriteSectionTest extends PlanningTestCase
{
    private const BODY = "# PHP 8 features\n## Typed at last\n\nIntro.\n\n## Setup\n\nInstall it.\n\n## Usage\n\nCall it.\n";

    public function testTheRewriteRequestHasTheVoiceOnlyWhenSet(): void
    {
        $prompts = [];
        $this->fakePrimary(static function ($input) use (&$prompts) {
            $prompts[] = (string) $input->getUserMessage()?->asText();

            return new TextResult('Install it with Composer.');
        });
        $article = $this->writtenArticle();
        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => 'Name the tool.']);
        $this->consume();
        // The worker cleared the entity manager: load the author again.
        ($this->em()->find(User::class, $this->author->getId()) ?? throw new \LogicException('No author.'))->setWritingProfile([], ['tone' => 'dry humor', 'samples' => 'A verbatim paragraph.']);
        $this->em()->flush();
        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Usage', 'instruction' => 'Shorter.']);
        $this->consume();

        self::assertStringNotContainsString('# Your voice', $prompts[0]);
        self::assertStringContainsString("# Your voice\n- tone: dry humor\n", $prompts[1]);
        self::assertStringContainsString('A verbatim paragraph.', $prompts[1]);
    }

    public function testAcceptReplacesOnlyThatSectionAndKeepsTheFrontMatter(): void
    {
        $article = $this->writtenArticle();
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return new TextResult("## Setup\n\nInstall it with Composer.");
        });

        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => 'Name the tool.']);
        self::assertResponseRedirects($this->url($article), 303);
        $this->consume();
        self::assertStringContainsString('Call it.', $prompt);
        self::assertStringContainsString("Rewrite this section: Setup\n\n\nInstall it.", $prompt);
        self::assertStringContainsString("The writer's instruction: Name the tool.", $prompt);

        $this->client->request('GET', $this->url($article));
        self::assertSelectorTextContains('[data-heading="Setup"] .before-after', 'Install it with Composer.');
        $this->client->submitForm('Accept');

        $file = $this->folder()->read($article->getFolder(), 'article.md');
        self::assertSame(['plan_id' => $article->getId(), 'title' => 'PHP 8 features', 'subtitle' => 'Typed at last'], $file['frontMatter']);
        self::assertSame(str_replace('Install it.', 'Install it with Composer.', self::BODY), $file['body']);
        self::assertSame(0, $this->em()->getRepository(SectionRewrite::class)->count([]));
    }

    public function testRewriteWithANoteUsesTheNoteSuggestionAsTheInstruction(): void
    {
        $article = $this->writtenArticle();
        $note = new ReaderNote($article, 'Usage', 'Too short.', 'Show a full example call.', 0);
        $this->em()->persist($note);
        $this->em()->flush();
        $this->fakePrimary(static fn () => new TextResult('Call it like this.'));

        $this->client->request('GET', $this->url($article));
        $this->client->submitForm('Rewrite with this note');

        $rewrite = $this->em()->getRepository(SectionRewrite::class)->findOneBy([]);
        self::assertSame('Usage', $rewrite?->getHeading());
        self::assertSame('Show a full example call.', $rewrite->getInstruction());
    }

    public function testAcceptRefusesWhenTheSectionWasHandEditedSinceTheRequest(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => new TextResult('Install it with Composer.'));
        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => 'Name the tool.']);
        $this->consume();
        $handEdited = str_replace('Install it.', 'Install it by hand.', self::BODY);
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features', 'subtitle' => 'Typed at last'], $handEdited);

        $this->client->request('POST', $this->url($article).'/sections/'.$this->rewrite()->getId(), ['decision' => 'accept']);

        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('.improve-error', 'This section changed since you asked. Ask again.');
        self::assertSame($handEdited, $this->folder()->read($article->getFolder(), 'article.md')['body']);
    }

    public function testRejectLeavesTheArticleUnchanged(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => new TextResult('Install it with Composer.'));
        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => 'Name the tool.']);
        $this->consume();

        $this->client->request('POST', $this->url($article).'/sections/'.$this->rewrite()->getId(), ['decision' => 'reject']);

        self::assertResponseRedirects($this->url($article), 303);
        self::assertSame(self::BODY, $this->folder()->read($article->getFolder(), 'article.md')['body']);
        self::assertSame(0, $this->em()->getRepository(SectionRewrite::class)->count([]));
    }

    public function testANewRequestReplacesThePendingRewriteOfTheSameSection(): void
    {
        $article = $this->writtenArticle();

        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => 'First']);
        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => 'Second']);

        $rewrites = $this->em()->getRepository(SectionRewrite::class)->findAll();
        self::assertSame(['Second'], array_map(static fn (SectionRewrite $r) => $r->getInstruction(), $rewrites));
    }

    public function testReaderNotesStayAfterAnAccept(): void
    {
        $article = $this->writtenArticle();
        $this->em()->persist(new ReaderNote($article, 'Setup', 'Why Composer?', 'Name the tool.', 0));
        $this->em()->flush();
        $this->fakePrimary(static fn () => new TextResult('Install it with Composer.'));
        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => 'Name the tool.']);
        $this->consume();

        $this->client->request('POST', $this->url($article).'/sections/'.$this->rewrite()->getId(), ['decision' => 'accept']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-heading="Setup"] .reader-notes', 'Why Composer?');
    }

    public function testAnEmptyInstructionOrAnUnknownSectionIsAFormError(): void
    {
        $article = $this->writtenArticle();

        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => ' ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.improve-error', 'Say what should change.');
        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Nope', 'instruction' => 'Do it.']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnEmptyAnswerFailsTheJobWithRetry(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => new TextResult('## Setup'));

        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => 'Name the tool.']);
        $this->consume();

        self::assertSame(LlmJobStatus::Failed, $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'rewrite_section'])?->getStatus());
        $this->client->request('GET', $this->url($article));
        self::assertSelectorExists('[data-heading="Setup"] .job-failed button');
    }

    public function testRewriteBeforeTheArticleIsWrittenIsAConflict(): void
    {
        $article = $this->planIn(ArticleState::Approved);

        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => 'Do it.']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testThePageShowsTheEmptyStateAndAQueuedRewrite(): void
    {
        $article = $this->writtenArticle();
        $this->client->request('GET', $this->url($article));
        self::assertSelectorTextContains('.empty-review', 'No reader review yet.');
        self::assertSelectorTextSame('.reader-review button', 'Get reader review');
        self::assertSelectorTextSame('nav.steps [aria-current]', 'Improve');

        $this->client->request('POST', $this->url($article).'/sections', ['heading' => 'Setup', 'instruction' => 'Name the tool.']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-heading="Setup"] .job-status', 'Queued');
        self::assertSelectorExists('[data-controller="page-poll"]');
    }

    public function testThePageShowsAWorkingReview(): void
    {
        $article = $this->writtenArticle();
        $this->client->request('POST', $this->url($article).'/review');
        $job = $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'review_article']);
        $job?->markWorking();
        $this->em()->flush();

        $this->client->request('GET', $this->url($article));

        self::assertSelectorTextContains('.reader-review .job-status', 'Working');
        self::assertSelectorNotExists('.reader-review form');
    }

    private function writtenArticle(): Article
    {
        $article = $this->planIn(ArticleState::Written);
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features', 'subtitle' => 'Typed at last'], self::BODY);

        return $article;
    }

    private function rewrite(): SectionRewrite
    {
        $this->em()->clear();

        return $this->em()->getRepository(SectionRewrite::class)->findOneBy([]) ?? throw new \LogicException('No rewrite.');
    }

    private function url(Article $article): string
    {
        return '/plans/'.$article->getId().'/improve';
    }

    private function folder(): ArticleFolder
    {
        return self::getContainer()->get(ArticleFolder::class);
    }
}
