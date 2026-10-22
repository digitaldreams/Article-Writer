<?php

namespace App\Tests\Planning\SuggestPosters;

use App\Planning\SuggestPosters\PosterIdea;
use App\Planning\SuggestPosters\PosterList;
use App\Planning\SuggestPosters\ProposedPoster;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\TextResult;

final class SuggestPostersTest extends PlanningTestCase
{
    public function testShowPosterIdeasOffersThreeVisualConceptsFromThePosterAgent(): void
    {
        $article = $this->writtenArticle();
        $prompt = '';
        $hasSystemPrompt = false;
        $this->fakePrimary(static function ($input) use (&$prompt, &$hasSystemPrompt) {
            $prompt = (string) $input->getUserMessage()?->asText();
            $hasSystemPrompt = null !== $input->getSystemMessage();

            return self::posters('A');
        });
        $this->client->request('GET', $this->posterUrl($article));
        self::assertSelectorTextSame('form[action$="/poster"] button', 'Show poster ideas');

        $this->client->request('POST', $this->posterUrl($article));
        self::assertResponseRedirects($this->posterUrl($article), 303);
        $this->consume();
        $this->client->request('GET', $this->posterUrl($article));

        self::assertSelectorCount(3, '.poster-idea');
        self::assertSelectorTextSame('.poster-idea h3', 'A 1');
        self::assertSelectorTextContains('.poster-idea dl', 'A 1 picture');
        self::assertSelectorTextSame('form[action$="/poster"] button', 'Suggest more');
        self::assertTrue($hasSystemPrompt);
        self::assertStringContainsString('Audience: PHP developers', $prompt);
        self::assertSame('test-poster', $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'suggest_posters'])?->getModel());
    }

    public function testSuggestMoreAddsThreeNewIdeasAndTellsTheAgentWhichOnesWereSeen(): void
    {
        $article = $this->writtenArticle();
        $round = 0;
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$round, &$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return self::posters(1 === ++$round ? 'A' : 'B');
        });
        $this->client->request('POST', $this->posterUrl($article));
        $this->consume();

        $this->client->request('POST', $this->posterUrl($article));
        $this->consume();

        self::assertStringContainsString('- A 1 — A 1 picture', $prompt);
        $this->client->request('GET', $this->posterUrl($article));
        self::assertSelectorCount(6, '.poster-idea');
    }

    public function testASharedIdeaIsImprovedIntoThreeIdeasShownUnderIt(): void
    {
        $article = $this->writtenArticle();
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return self::posters('A');
        });
        $this->client->request('GET', $this->posterUrl($article));
        self::assertSelectorTextSame('section form:last-of-type label', 'Do you have an idea to make the poster better? Share it');

        $this->client->request('POST', $this->posterUrl($article), ['idea' => '  An elephant holding a padlock  ']);
        self::assertResponseRedirects($this->posterUrl($article), 303);
        $this->consume();

        self::assertStringContainsString("# The writer's idea for the poster\nAn elephant holding a padlock", $prompt);
        self::assertStringContainsString('Typed at last.', $prompt);
        $this->client->request('GET', $this->posterUrl($article));
        self::assertSelectorCount(3, '.poster-idea');
        self::assertSelectorCount(1, '.writer-idea');
        self::assertSelectorTextSame('.writer-idea', 'Your idea: An elephant holding a padlock');
    }

    public function testSharingAnEmptyIdeaIsAFormError(): void
    {
        $article = $this->writtenArticle();

        $this->client->request('POST', $this->posterUrl($article), ['idea' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('.share-idea .field-error', 'Write your idea first.');
        self::assertSame(0, $this->em()->getRepository(LlmJob::class)->count(['step' => 'suggest_posters']));
    }

    public function testTheAgentGetsTheWholeArticleUpToTheLastSection(): void
    {
        $article = $this->writtenArticle();
        $body = "## Start\n\n".str_repeat("A long middle paragraph about typed properties.\n\n", 2_000)."## Last section\n\nThe very end.\n";
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features'], $body);
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return self::posters('A');
        });

        $this->client->request('POST', $this->posterUrl($article));
        $this->consume();

        self::assertStringContainsString($body, $prompt);
    }

    public function testAskingForMorePosterIdeasWhileARoundIsComingIsAConflict(): void
    {
        $article = $this->writtenArticle();
        $this->client->request('POST', $this->posterUrl($article));

        $this->client->request('POST', $this->posterUrl($article), ['idea' => 'A broken lock']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->em()->getRepository(LlmJob::class)->count(['step' => 'suggest_posters']));
    }

    public function testPosterIdeasBeforeTheArticleIsWrittenAreAConflict(): void
    {
        $article = $this->planIn(ArticleState::Approved);

        $this->client->request('POST', $this->posterUrl($article));

        self::assertResponseStatusCodeSame(409);
    }

    public function testAWrongNumberOfIdeasFailsTheJobWithRetry(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => new ObjectResult(new PosterList([new ProposedPoster('Only one', 'a cat', 'centered', 'black', '<svg/>')])));

        $this->client->request('POST', $this->posterUrl($article));
        $this->consume();

        self::assertSame(LlmJobStatus::Failed, $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'suggest_posters'])?->getStatus());
        $this->client->request('GET', $this->posterUrl($article));
        self::assertSelectorExists('.job-failed button');
        self::assertSelectorNotExists('.poster-idea');
    }

    public function testGeneratePosterDrawsTheIdeaWithTheImageModelAndShowsIt(): void
    {
        $article = $this->writtenArticle();
        $idea = $this->idea($article);
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = $input;

            return new BinaryResult('png-bytes', 'image/png');
        });
        $this->client->request('GET', $this->posterUrl($article));
        self::assertSelectorTextSame('.poster-idea button', 'Generate poster');

        $this->client->request('POST', $this->posterUrl($article).'/'.$idea->getId());
        self::assertResponseRedirects($this->posterUrl($article), 303);
        $this->consume();

        self::assertStringContainsString('spelled exactly: Readonly at last', $prompt);
        self::assertStringContainsString('Picture: A sealed glass capsule', $prompt);
        self::assertSame('test-poster-image', $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'generate_poster'])?->getModel());
        $this->client->request('GET', $this->posterUrl($article));
        self::assertSelectorExists('img.poster-image[alt="Poster: Readonly at last"]');
        self::assertSelectorTextSame('.poster-idea button', 'Generate again');
        $this->client->request('GET', $this->posterUrl($article).'/'.$idea->getId().'.png');
        self::assertResponseIsSuccessful();
        self::assertSame('png-bytes', file_get_contents($this->folder()->pathOf($article->getFolder(), 'images/poster-'.$idea->getId().'.png')));
    }

    public function testAnAnswerThatIsNotAnImageFailsTheDrawJobWithRetry(): void
    {
        $article = $this->writtenArticle();
        $idea = $this->idea($article);
        $this->fakePrimary(static fn () => new TextResult('I can only write.'));

        $this->client->request('POST', $this->posterUrl($article).'/'.$idea->getId());
        $this->consume();

        self::assertSame(LlmJobStatus::Failed, $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'generate_poster'])?->getStatus());
        $this->client->request('GET', $this->posterUrl($article));
        self::assertSelectorExists('.job-failed button');
        self::assertSelectorNotExists('img.poster-image');
    }

    public function testGeneratingAnUnknownIdeaIsNotFoundAndBeforeTheArticleIsWrittenIsAConflict(): void
    {
        $this->client->request('POST', $this->posterUrl($this->writtenArticle()).'/999999');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', $this->posterUrl($this->planIn(ArticleState::Approved)).'/1');
        self::assertResponseStatusCodeSame(409);
    }

    public function testEachIdeaShowsItsSketchAsAnImage(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => self::posters('A'));

        $this->client->request('POST', $this->posterUrl($article));
        $this->consume();
        $this->client->request('GET', $this->posterUrl($article));

        self::assertSelectorCount(3, '.poster-idea img.poster-sketch');
        $src = (string) $this->client->getCrawler()->filter('img.poster-sketch')->first()->attr('src');
        self::assertStringStartsWith('data:image/svg+xml;utf8,', $src);
        self::assertStringContainsString('fill="navy"', rawurldecode($src));
    }

    public function testASketchThatIsNotASmallSvgIsDroppedAndTheIdeaIsStillSaved(): void
    {
        $article = $this->writtenArticle();
        $this->fakePrimary(static fn () => new ObjectResult(new PosterList([
            new ProposedPoster('Not svg', 'a cat', 'centered', 'black', '<div>nope</div>'),
            new ProposedPoster('Too big', 'a dog', 'centered', 'black', '<svg>'.str_repeat(' ', 11_000).'</svg>'),
            new ProposedPoster('Fine', 'a bird', 'centered', 'black', '<svg viewBox="0 0 1200 630"></svg>'),
        ])));

        $this->client->request('POST', $this->posterUrl($article));
        $this->consume();

        $sketches = [];
        foreach ($this->em()->getRepository(PosterIdea::class)->findBy(['article' => $article], ['id' => 'ASC']) as $idea) {
            $sketches[$idea->getHeadline()] = $idea->getSketch();
        }
        self::assertSame(['Not svg' => null, 'Too big' => null, 'Fine' => '<svg viewBox="0 0 1200 630"></svg>'], $sketches);
    }

    public function testAnIdeaWithoutASketchStillShows(): void
    {
        $article = $this->writtenArticle();
        $this->idea($article);

        $this->client->request('GET', $this->posterUrl($article));

        self::assertSelectorTextSame('.poster-idea h3', 'Readonly at last');
        self::assertSelectorNotExists('img.poster-sketch');
    }

    private function idea(Article $article): PosterIdea
    {
        $idea = new PosterIdea($article, 1, 'Readonly at last', 'A sealed glass capsule holding a value card', 'headline left, capsule right', 'navy, cyan');
        $this->em()->persist($idea);
        $this->em()->flush();

        return $idea;
    }

    public function testTheAgentWritesForTheAuthorsLanguageLevel(): void
    {
        $article = $this->writtenArticle();
        $article->getUser()->updateProfile('', '', 'intermediate');
        $this->em()->flush();
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getSystemMessage()?->getContent();

            return self::posters('A');
        });

        $this->client->request('POST', $this->posterUrl($article));
        $this->consume();

        self::assertStringContainsString('for a reader with intermediate English', $prompt);
    }

    private function writtenArticle(): Article
    {
        $article = $this->planIn(ArticleState::Written);
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId(), 'title' => 'PHP 8 features'], "# PHP 8 features\n\nTyped at last.\n");

        return $article;
    }

    private static function posters(string $prefix): ObjectResult
    {
        return new ObjectResult(new PosterList(array_map(static fn (int $i) => new ProposedPoster($prefix.' '.$i, $prefix.' '.$i.' picture', 'headline on top', 'navy, orange', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630"><rect width="1200" height="630" fill="navy"/></svg>'), range(1, 3))));
    }

    private function posterUrl(Article $article): string
    {
        return '/plans/'.$article->getId().'/poster';
    }

    private function folder(): ArticleFolder
    {
        return self::getContainer()->get(ArticleFolder::class);
    }
}
