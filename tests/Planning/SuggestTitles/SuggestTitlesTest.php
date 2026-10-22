<?php

namespace App\Tests\Planning\SuggestTitles;

use App\Planning\SuggestTitles\ProposedTitle;
use App\Planning\SuggestTitles\TitleList;
use App\Planning\SuggestTitles\TitleSuggestion;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\ObjectResult;

final class SuggestTitlesTest extends PlanningTestCase
{
    public function testShowTitleSuggestionsOffersFiveTitleAndSubtitlePairsFromTheTitleAgent(): void
    {
        $article = $this->writtenArticle();
        $prompt = '';
        $hasSystemPrompt = false;
        $this->fakePrimary(static function ($input) use (&$prompt, &$hasSystemPrompt) {
            $prompt = (string) $input->getUserMessage()?->asText();
            $hasSystemPrompt = null !== $input->getSystemMessage();

            return self::titles('A');
        });
        $this->client->request('GET', $this->titlesUrl($article));
        self::assertSelectorTextSame('button.button-secondary', 'Show title suggestions');

        $this->client->request('POST', $this->titlesUrl($article));
        self::assertResponseRedirects($this->titlesUrl($article), 303);
        $this->consume();
        $this->client->request('GET', $this->titlesUrl($article));

        self::assertSelectorCount(5, 'input[type=radio][name=suggestion]');
        self::assertSelectorTextSame('label:first-of-type .suggested-subtitle', 'A 1 subtitle');
        self::assertSelectorTextContains('button.button-secondary', 'Suggest more');
        self::assertTrue($hasSystemPrompt);
        self::assertStringContainsString('Audience: PHP developers', $prompt);
        self::assertStringContainsString('Typed at last', $prompt);
        self::assertSame('test-title', $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'suggest_titles'])?->getModel());
    }

    public function testSuggestMoreAddsFiveNewTitlesAndTellsTheAgentWhichOnesWereSeen(): void
    {
        $article = $this->writtenArticle();
        $round = 0;
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$round, &$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return self::titles(1 === ++$round ? 'A' : 'B');
        });
        $this->client->request('POST', $this->titlesUrl($article));
        $this->consume();

        $this->client->request('POST', $this->titlesUrl($article));
        $this->consume();

        self::assertStringContainsString('- A 1 — A 1 subtitle', $prompt);
        $this->client->request('GET', $this->titlesUrl($article));
        self::assertSelectorCount(10, 'input[type=radio][name=suggestion]');
    }

    public function testTheWritersIdeaReachesTheAgentAndShowsAboveItsRound(): void
    {
        $article = $this->writtenArticle();
        $prompts = [];
        $this->fakePrimary(static function ($input) use (&$prompts) {
            $prompts[] = (string) $input->getUserMessage()?->asText();

            return self::titles(1 === \count($prompts) ? 'A' : 'B');
        });

        $this->client->request('POST', $this->titlesUrl($article), ['idea' => '  Start with "Stop writing getters"  ']);
        $this->consume();
        $this->client->request('POST', $this->titlesUrl($article), ['idea' => '']);
        $this->consume();

        self::assertStringContainsString("# The writer's idea for the title\nStart with \"Stop writing getters\"", $prompts[0]);
        self::assertStringNotContainsString("writer's idea", $prompts[1]);
        $this->client->request('GET', $this->titlesUrl($article));
        self::assertSelectorCount(1, '.writer-idea');
        self::assertSelectorTextContains('.writer-idea', 'Start with "Stop writing getters"');
    }

    public function testChoosingAPairUpdatesTheTitleTheFrontMatterAndTheHeadings(): void
    {
        $article = $this->writtenArticle();
        $suggestion = new TitleSuggestion($article, 1, 'Typed properties cost $0 now', 'Typed properties, hooks and private(set) in PHP 8.4');
        $this->em()->persist($suggestion);
        $this->em()->flush();

        $this->client->request('POST', $this->titlesUrl($article).'/choose', ['suggestion' => $suggestion->getId()]);

        self::assertResponseRedirects($this->titlesUrl($article), 303);
        self::assertSame('Typed properties cost $0 now', $this->reload($article)->getTitle());
        $file = $this->folder()->read($article->getFolder(), 'article.md');
        self::assertSame(['plan_id' => $article->getId(), 'title' => 'Typed properties cost $0 now', 'subtitle' => 'Typed properties, hooks and private(set) in PHP 8.4'], $file['frontMatter']);
        self::assertSame("# Typed properties cost \$0 now\n## Typed properties, hooks and private(set) in PHP 8.4\n\nTyped at last.\n", $file['body']);
        $this->client->request('GET', '/plans/'.$article->getId().'/article');
        self::assertSelectorTextSame('h1', 'Typed properties cost $0 now');
        self::assertSelectorTextSame('p.subtitle', 'Typed properties, hooks and private(set) in PHP 8.4');
    }

    public function testChoosingWithoutAPickedTitleIsAFormError(): void
    {
        $article = $this->writtenArticle();

        $this->client->request('POST', $this->titlesUrl($article).'/choose', ['suggestion' => '999999']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAskingForMoreTitlesWhileARoundIsComingIsAConflict(): void
    {
        $article = $this->writtenArticle();
        $this->client->request('POST', $this->titlesUrl($article));

        $this->client->request('POST', $this->titlesUrl($article), ['idea' => 'Mention hooks']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->em()->getRepository(LlmJob::class)->count(['step' => 'suggest_titles']));
    }

    public function testTitlesBeforeTheArticleIsWrittenAreAConflict(): void
    {
        $article = $this->planIn(ArticleState::Approved);

        $this->client->request('POST', $this->titlesUrl($article));
        self::assertResponseStatusCodeSame(409);
        $this->client->request('POST', $this->titlesUrl($article).'/choose', ['suggestion' => '1']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAWrongNumberOfTitlesFailsTheJobWithRetry(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => new ObjectResult(new TitleList([new ProposedTitle('Only one', 'and no more')])));

        $this->client->request('POST', $this->titlesUrl($article));
        $this->consume();

        self::assertSame(LlmJobStatus::Failed, $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'suggest_titles'])?->getStatus());
        $this->client->request('GET', $this->titlesUrl($article));
        self::assertSelectorExists('.job-failed button');
        self::assertSelectorNotExists('input[type=radio][name=suggestion]');
    }

    public function testTheAgentGetsTheWholeArticleUpToTheLastSection(): void
    {
        $article = $this->writtenArticle();
        $body = "## Start\n\n".str_repeat("A long middle paragraph about typed properties.\n\n", 2_000)."## Last section\n\nThe very end.\n";
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features'], $body);
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return self::titles('A');
        });

        $this->client->request('POST', $this->titlesUrl($article));
        $this->consume();

        self::assertStringContainsString($body, $prompt);
    }

    public function testTheAgentGetsTheArticleWithoutItsCodeBlocks(): void
    {
        $article = $this->writtenArticle();
        $body = "## Hooks\n\nBefore:\n\n```php\nclass User { public string \$name; }\n```\n\nAfter:\n\n~~~\nget => ucfirst(\$this->name);\n~~~\n\nUse `get` hooks.\n";
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features'], $body);
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return self::titles('A');
        });

        $this->client->request('POST', $this->titlesUrl($article));
        $this->consume();

        self::assertStringContainsString("## Hooks\n\nBefore:\n\n(code omitted)\n\nAfter:\n\n(code omitted)\n\nUse `get` hooks.\n", $prompt);
        self::assertStringNotContainsString('class User', $prompt);
    }

    public function testTheAgentWritesForTheAuthorsLanguageLevel(): void
    {
        $article = $this->writtenArticle();
        $article->getUser()->updateProfile('', '', 'intermediate');
        $this->em()->flush();
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getSystemMessage()?->getContent();

            return self::titles('A');
        });

        $this->client->request('POST', $this->titlesUrl($article));
        $this->consume();

        self::assertStringContainsString('for a reader with intermediate English', $prompt);
    }

    private function writtenArticle(): Article
    {
        $article = $this->planIn(ArticleState::Written);
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features'], "# PHP 8 features\n\nTyped at last.\n");

        return $article;
    }

    private static function titles(string $prefix): ObjectResult
    {
        return new ObjectResult(new TitleList(array_map(static fn (int $i) => new ProposedTitle($prefix.' '.$i, $prefix.' '.$i.' subtitle'), range(1, 5))));
    }

    private function titlesUrl(Article $article): string
    {
        return '/plans/'.$article->getId().'/titles';
    }

    private function folder(): ArticleFolder
    {
        return self::getContainer()->get(ArticleFolder::class);
    }
}
