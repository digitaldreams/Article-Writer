<?php

namespace App\Tests\Planning\SuggestPunchlines;

use App\Planning\SuggestPunchlines\PunchlineList;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\ObjectResult;

final class SuggestPunchlinesTest extends PlanningTestCase
{
    public function testSuggestPunchlinesOffersFiveCopyableLinesFromTheShareAgent(): void
    {
        $article = $this->writtenArticle();
        $prompt = '';
        $systemPrompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt, &$systemPrompt) {
            $prompt = (string) $input->getUserMessage()?->asText();
            $systemPrompt = (string) $input->getSystemMessage()?->getContent();

            return self::punchlines('A');
        });

        $this->client->request('POST', $this->shareUrl($article));
        self::assertResponseRedirects($this->shareUrl($article), 303);
        $this->consume();
        $this->client->request('GET', $this->shareUrl($article));

        self::assertSelectorCount(5, '.punchline button');
        self::assertSelectorTextSame('.punchline [data-copy-target=source]', 'A 1');
        self::assertSelectorTextContains('.share-idea button', 'Suggest more');
        self::assertStringContainsString("doesn't deliver", $systemPrompt);
        self::assertStringContainsString('Audience: PHP developers', $prompt);
        self::assertStringContainsString('Typed at last', $prompt);
        self::assertSame('test-title', $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'suggest_punchlines'])?->getModel());
    }

    public function testSuggestMoreTellsTheAgentWhatWasSeenAndTheWritersIdea(): void
    {
        $article = $this->writtenArticle();
        $prompts = [];
        $this->fakePrimary(static function ($input) use (&$prompts) {
            $prompts[] = (string) $input->getUserMessage()?->asText();

            return self::punchlines(1 === \count($prompts) ? 'A' : 'B');
        });

        $this->client->request('POST', $this->shareUrl($article));
        $this->consume();
        $this->client->request('POST', $this->shareUrl($article), ['idea' => ' Lead with the getter pain ']);
        $this->consume();

        self::assertStringNotContainsString("writer's idea", $prompts[0]);
        self::assertStringContainsString('- A 1', $prompts[1]);
        self::assertStringContainsString("# The writer's idea for the punchline\nLead with the getter pain", $prompts[1]);
        $this->client->request('GET', $this->shareUrl($article));
        self::assertSelectorCount(10, '.punchline');
        self::assertSelectorTextContains('.writer-idea', 'Lead with the getter pain');
    }

    public function testARoundSavesFiveMediumTopicsToArticleMdAndShowsThem(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => self::punchlines('A', ['#Programming', 'PHP', ' ', 'Symfony', 'Messenger', 'RabbitMQ', 'Sixth']));

        $this->client->request('POST', $this->shareUrl($article));
        $this->consume();

        $file = $this->folder()->read($article->getFolder(), 'article.md');
        self::assertSame(['Programming', 'PHP', 'Symfony', 'Messenger', 'RabbitMQ'], $file['frontMatter']['topics']);
        self::assertSame('PHP 8 features', $file['frontMatter']['title']);
        self::assertSame("# PHP 8 features\n\nTyped at last.\n", $file['body']);
        $this->client->request('GET', $this->shareUrl($article));
        self::assertSelectorTextSame('.topics [data-copy-target=source]', 'Programming, PHP, Symfony, Messenger, RabbitMQ');
    }

    public function testARoundWithoutTopicsKeepsTheSavedOnes(): void
    {
        $article = $this->writtenArticle(['PHP']);
        $this->fakePrimary(static fn () => self::punchlines('A'));

        $this->client->request('POST', $this->shareUrl($article));
        $this->consume();

        self::assertSame(['PHP'], $this->folder()->read($article->getFolder(), 'article.md')['frontMatter']['topics']);
        $this->client->request('GET', $this->shareUrl($article));
        self::assertSelectorCount(5, '.punchline');
    }

    public function testAskingForMorePunchlinesWhileARoundIsComingIsAConflict(): void
    {
        $article = $this->writtenArticle();
        $this->client->request('POST', $this->shareUrl($article));

        $this->client->request('POST', $this->shareUrl($article), ['idea' => 'Short and bold']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->em()->getRepository(LlmJob::class)->count(['step' => 'suggest_punchlines']));
    }

    public function testPunchlinesBeforeTheArticleIsWrittenAreAConflict(): void
    {
        $article = $this->planIn(ArticleState::Approved);

        $this->client->request('POST', $this->shareUrl($article));

        self::assertResponseStatusCodeSame(409);
    }

    public function testAWrongNumberOfPunchlinesFailsTheJobWithRetry(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => new ObjectResult(new PunchlineList(['Only one'])));

        $this->client->request('POST', $this->shareUrl($article));
        $this->consume();

        self::assertSame(LlmJobStatus::Failed, $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'suggest_punchlines'])?->getStatus());
        $this->client->request('GET', $this->shareUrl($article));
        self::assertSelectorExists('.job-failed button');
        self::assertSelectorNotExists('.punchline');
    }

    public function testTheAgentWritesForTheAuthorsLanguageLevel(): void
    {
        $article = $this->writtenArticle();
        $article->getUser()->updateProfile('', '', 'intermediate');
        $this->em()->flush();
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getSystemMessage()?->getContent();

            return self::punchlines('A');
        });

        $this->client->request('POST', $this->shareUrl($article));
        $this->consume();

        self::assertStringContainsString('for a reader with intermediate English', $prompt);
    }

    /**
     * @param list<string> $topics
     */
    private function writtenArticle(array $topics = []): Article
    {
        $article = $this->planIn(ArticleState::Written);
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features'] + ($topics ? ['topics' => $topics] : []), "# PHP 8 features\n\nTyped at last.\n");

        return $article;
    }

    /**
     * @param list<string> $topics
     */
    private static function punchlines(string $prefix, array $topics = []): ObjectResult
    {
        return new ObjectResult(new PunchlineList(array_map(static fn (int $i) => $prefix.' '.$i, range(1, 5)), $topics));
    }

    private function folder(): ArticleFolder
    {
        return self::getContainer()->get(ArticleFolder::class);
    }

    private function shareUrl(Article $article): string
    {
        return '/plans/'.$article->getId().'/share';
    }
}
