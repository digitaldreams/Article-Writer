<?php

namespace App\Tests\Planning\FinalCheck;

use App\Planning\FinalCheck\ArticleRefactor;
use App\Planning\FinalCheck\CheckFinding;
use App\Planning\FinalCheck\CheckNote;
use App\Planning\FinalCheck\CheckReport;
use App\Planning\FinalCheck\RefactorAnswer;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\ObjectResult;

final class FinalCheckTest extends PlanningTestCase
{
    private const BODY = "# PHP 8 features\n## Typed at last\n\n## Setup\n\nInstall it.\n\n## Usage\n\nCall it.\n";

    public function testTheCheckReadsTheChosenTitlePlanOutlineAndArticleAndShowsNotesPerSection(): void
    {
        $article = $this->writtenArticle();
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return self::report(new CheckFinding('Setup', 'Composer is never named.', 'Name Composer.'), new CheckFinding('Conclusion', 'The title promises hooks.', 'Add a hooks section.'));
        });

        $this->client->request('POST', $this->url($article));
        self::assertResponseRedirects($this->url($article), 303);
        $this->consume();

        self::assertStringContainsString('Rough flow: typed properties, hooks', $prompt);
        self::assertStringContainsString('## Setup: install', $prompt);
        self::assertStringContainsString("- Setup\n- Usage", $prompt);
        self::assertStringContainsString("# PHP 8 features\n## Typed at last", $prompt);
        self::assertSame('check', $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'check_article'])?->getRole());
        $this->client->request('GET', $this->url($article));
        self::assertSelectorTextContains('[data-section="Setup"] .check-notes', 'Composer is never named.');
        self::assertSelectorTextContains('[data-section="General"] .check-notes', 'The title promises hooks.');
        self::assertSelectorTextSame('nav.steps [aria-current]', 'Final check');
    }

    public function testANewCheckReplacesTheOldNotes(): void
    {
        $article = $this->writtenArticle();
        $round = 0;
        $this->fakePrimary(static function () use (&$round) {
            return self::report(new CheckFinding('Setup', 1 === ++$round ? 'First' : 'Second', 'Fix it.'));
        });

        $this->client->request('POST', $this->url($article));
        $this->consume();
        $this->client->request('POST', $this->url($article));
        $this->consume();

        self::assertSame(['Second'], array_map(static fn (CheckNote $n) => $n->getProblem(), $this->notes($article)));
    }

    public function testMoreThanEightNotesKeepTheFirstEight(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => self::report(...array_map(static fn (int $i) => new CheckFinding('Setup', "Note $i", 'Fix it.'), range(1, 9))));

        $this->client->request('POST', $this->url($article));
        $this->consume();

        $notes = $this->notes($article);
        self::assertCount(8, $notes);
        self::assertSame('Note 8', end($notes)->getProblem());
    }

    public function testCheckAndRefactorBeforeTheArticleIsWrittenAreAConflict(): void
    {
        $article = $this->planIn(ArticleState::Approved);

        $this->client->request('POST', $this->url($article));
        self::assertResponseStatusCodeSame(409);
        $this->client->request('POST', $this->url($article).'/refactor', ['notes' => [1]]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testRefactorWithoutATickedNoteIsAFormError(): void
    {
        $article = $this->writtenArticle();
        $this->addNote($article, 'Setup', 'Composer is never named.');

        $this->client->request('POST', $this->url($article).'/refactor');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.final-check-error', 'Tick at least one note.');
        self::assertSame(0, $this->em()->getRepository(ArticleRefactor::class)->count([]));
    }

    public function testTheRefactorWorksFromTheTickedNotesAndKeepsTheChosenTitleLines(): void
    {
        $article = $this->writtenArticle();
        $ticked = $this->addNote($article, 'Setup', 'Composer is never named.');
        $this->addNote($article, 'Usage', 'No example call.');
        $prompt = '';
        $system = '';
        $this->fakePrimary(static function ($input) use (&$prompt, &$system) {
            $prompt = (string) $input->getUserMessage()?->asText();
            $system = (string) $input->getSystemMessage()?->getContent();

            return new ObjectResult(new RefactorAnswer("# Another title\n## Another subtitle\n\n## Setup\n\nInstall it with Composer.\n\n## Usage\n\nCall it.\n", ['Code: Setup names Composer.', ' ']));
        });

        $this->client->request('POST', $this->url($article).'/refactor', ['notes' => [$ticked->getId()]]);
        self::assertResponseRedirects($this->url($article), 303);
        $this->consume();

        self::assertStringContainsString('[Setup] Composer is never named.', $prompt);
        self::assertStringNotContainsString('No example call.', $prompt);
        self::assertStringContainsString('Keep the heading levels: sections "## ", parts "### ", nothing else.', $system);
        $refactor = $this->refactor();
        self::assertSame("# PHP 8 features\n## Typed at last\n\n## Setup\n\nInstall it with Composer.\n\n## Usage\n\nCall it.\n", $refactor->getAfter());
        self::assertSame(['Code: Setup names Composer.'], $refactor->getChanges());
        self::assertSame('check', $refactor->getJob()?->getRole());
    }

    public function testAcceptWritesTheRefactoredArticleAndRemovesOnlyTheNotesItUsed(): void
    {
        $article = $this->writtenArticle();
        $ticked = $this->addNote($article, 'Setup', 'Composer is never named.');
        $this->addNote($article, 'Usage', 'No example call.');
        $this->refactorDone($article, $ticked, 'Install it with Composer.');

        $this->client->request('GET', $this->url($article));
        self::assertSelectorTextContains('.refactor-changes', 'Code: Setup names Composer.');
        $this->client->submitForm('Accept');

        $file = $this->folder()->read($article->getFolder(), 'article.md');
        self::assertSame(['plan_id' => $article->getId(), 'title' => 'PHP 8 features', 'subtitle' => 'Typed at last'], $file['frontMatter']);
        self::assertSame(str_replace('Install it.', 'Install it with Composer.', self::BODY), $file['body']);
        self::assertSame(['No example call.'], array_map(static fn (CheckNote $n) => $n->getProblem(), $this->notes($article)));
        self::assertSame(0, $this->em()->getRepository(ArticleRefactor::class)->count([]));
    }

    public function testAcceptRefusesWhenTheArticleChangedSinceTheRequest(): void
    {
        $article = $this->writtenArticle();
        $this->refactorDone($article, $this->addNote($article, 'Setup', 'Composer is never named.'), 'Install it with Composer.');
        $edited = str_replace('Call it.', 'Call it twice.', self::BODY);
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features', 'subtitle' => 'Typed at last'], $edited);

        $this->client->request('POST', $this->url($article).'/refactor/'.$this->refactor()->getId(), ['decision' => 'accept']);

        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('.final-check-error', 'The article changed since you asked.');
        self::assertSame($edited, $this->folder()->read($article->getFolder(), 'article.md')['body']);
    }

    public function testRejectKeepsTheArticleAndTheNotes(): void
    {
        $article = $this->writtenArticle();
        $this->refactorDone($article, $this->addNote($article, 'Setup', 'Composer is never named.'), 'Install it with Composer.');

        $this->client->request('GET', $this->url($article));
        $this->client->submitForm('Reject');

        self::assertSame(self::BODY, $this->folder()->read($article->getFolder(), 'article.md')['body']);
        self::assertCount(1, $this->notes($article));
        self::assertSame(0, $this->em()->getRepository(ArticleRefactor::class)->count([]));
    }

    public function testThePageShowsTheEmptyStateBeforeTheFirstCheck(): void
    {
        $article = $this->writtenArticle();

        $this->client->request('GET', $this->url($article));

        self::assertSelectorTextContains('.empty-check', 'No final check yet.');
        self::assertSelectorTextSame('.final-check .page-head button', 'Run final check');
    }

    public function testAnotherAuthorsArticleIsNotFound(): void
    {
        $theirs = Article::plan(self::newAuthor(), 'theirs', 'Theirs');
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->em()->clear();

        $this->client->request('GET', $this->url($theirs));

        self::assertResponseStatusCodeSame(404);
    }

    private function writtenArticle(): Article
    {
        $article = $this->planIn(ArticleState::Written);
        $this->folder()->write($article->getFolder(), 'idea.md', ['plan_id' => $article->getId()], "# PHP 8 features\n\nRough flow: typed properties, hooks\n");
        $this->folder()->write($article->getFolder(), 'outline.md', ['plan_id' => $article->getId()], "## Setup: install\n\n## Usage: call\n");
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features', 'subtitle' => 'Typed at last'], self::BODY);

        return $article;
    }

    private function addNote(Article $article, string $section, string $problem): CheckNote
    {
        $note = new CheckNote($article, $section, $problem, 'Fix it.', 0);
        $this->em()->persist($note);
        $this->em()->flush();

        return $note;
    }

    private function refactorDone(Article $article, CheckNote $note, string $setup): void
    {
        $this->fakePrimary(static fn () => new ObjectResult(new RefactorAnswer(str_replace('Install it.', $setup, self::BODY), ['Code: Setup names Composer.'])));
        $this->client->request('POST', $this->url($article).'/refactor', ['notes' => [$note->getId()]]);
        $this->consume();
    }

    /** @return list<CheckNote> */
    private function notes(Article $article): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(CheckNote::class)->findBy(['article' => $article->getId()], ['position' => 'ASC']);
    }

    private function refactor(): ArticleRefactor
    {
        $this->em()->clear();

        return $this->em()->getRepository(ArticleRefactor::class)->findOneBy([]) ?? throw new \LogicException('No refactor.');
    }

    private static function report(CheckFinding ...$notes): ObjectResult
    {
        return new ObjectResult(new CheckReport(array_values($notes)));
    }

    private function folder(): ArticleFolder
    {
        return self::getContainer()->get(ArticleFolder::class);
    }

    private function url(Article $article): string
    {
        return '/plans/'.$article->getId().'/final-check';
    }
}
