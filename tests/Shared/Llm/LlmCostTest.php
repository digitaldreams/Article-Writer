<?php

namespace App\Tests\Shared\Llm;

use App\Planning\SuggestPosters\PosterIdea;
use App\Planning\SuggestPosters\PosterList;
use App\Planning\SuggestPosters\ProposedPoster;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmCost;
use App\Shared\Llm\LlmJob;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Event\ResultConvertedEvent;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class LlmCostTest extends PlanningTestCase
{
    public function testEveryModelCallOfAJobAddsItsTokensToTheJob(): void
    {
        $article = $this->planIn(ArticleState::Written);
        self::getContainer()->get(ArticleFolder::class)->write($article->getFolder(), 'article.md', ['title' => 'PHP'], "# PHP\n");
        $this->fakePrimary(function () {
            $result = new ObjectResult(new PosterList(array_map(static fn (int $i) => new ProposedPoster("H $i", 'p', 'l', 'c', '<svg/>'), range(1, 3))));
            // A real platform reports every call; one agent call can make two of them.
            $this->reportCall(new TextResult('tool step'), new TokenUsage(promptTokens: 100, completionTokens: 10));
            $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 1_000, completionTokens: 200));

            return $this->reportCall($result);
        });

        $this->client->request('POST', '/plans/'.$article->getId().'/poster');
        $this->consume();

        $job = $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'suggest_posters']) ?? throw new \LogicException('No job.');
        self::assertSame([1_100, 210, 0.0], [$job->getInputTokens(), $job->getOutputTokens(), $job->getCost()]);
        $this->client->request('GET', '/plans/'.$article->getId().'/poster');
        self::assertSelectorTextSame('.steps-cost', 'Total cost: $0.0000');
    }

    public function testAnImageCallIsCountedFromTheUsageInTheModelAnswer(): void
    {
        $article = $this->planIn(ArticleState::Written);
        $idea = new PosterIdea($article, 1, 'H', 'p', 'l', 'c');
        $this->em()->persist($idea);
        $this->em()->flush();
        $this->fakePrimary(function () {
            $result = new BinaryResult('png', 'image/png');
            $result->setRawResult(new InMemoryRawResult(['usage' => ['input_tokens' => 50, 'output_tokens' => 4_000]]));

            return $this->reportCall($result);
        });

        $this->client->request('POST', '/plans/'.$article->getId().'/poster/'.$idea->getId());
        $this->consume();

        $job = $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'generate_poster']);
        self::assertSame([50, 4_000], [$job?->getInputTokens(), $job?->getOutputTokens()]);
    }

    public function testCostIsTokensTimesThePricePerMillion(): void
    {
        $cost = new LlmCost(['openai' => ['gpt-x' => ['in' => 2.0, 'out' => 10.0]]], self::getContainer()->get(EntityManagerInterface::class));

        self::assertEqualsWithDelta(0.004, $cost->of('openai', 'gpt-x', 1_000, 200), 1e-12);
        self::assertNull($cost->of('openai', 'not-priced', 1_000, 200));
        self::assertSame(0.0, $cost->of('ollama', 'llama3.1', 1_000, 200));
    }

    private function reportCall(ResultInterface $result, ?TokenUsage $usage = null): ResultInterface
    {
        if (null !== $usage) {
            $result->getMetadata()->add('token_usage', $usage);
        }
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new ResultConvertedEvent(new Model('test'), $result));

        return $result;
    }
}
