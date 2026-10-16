<?php

namespace App\Shared\Llm;

use App\Shared\Article\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/** Prices a job's tokens from config/packages/ai_prices.php, and sums an article's cost. */
final readonly class LlmCost
{
    /** @param array<string, array<string, array{in: float, out: float}>> $prices */
    public function __construct(
        #[Autowire('%app.ai.prices%')]
        private array $prices,
        private EntityManagerInterface $em,
    ) {
    }

    /** USD; null when the model has no price. Ollama runs locally, so it is free. */
    public function of(string $provider, string $model, int $inputTokens, int $outputTokens): ?float
    {
        if ('ollama' === $provider) {
            return 0.0;
        }
        $price = $this->prices[$provider][$model] ?? null;

        return null === $price ? null : ($inputTokens * $price['in'] + $outputTokens * $price['out']) / 1_000_000;
    }

    /** USD over all of the article's jobs; null when none of them has a cost yet. */
    #[AsTwigFunction('article_cost')]
    public function ofArticle(Article $article): ?float
    {
        $sum = $this->em->createQuery('SELECT SUM(j.cost) FROM '.LlmJob::class.' j WHERE j.article = :article')
            ->setParameter('article', $article)
            ->getSingleScalarResult();

        return null === $sum ? null : (float) $sum;
    }
}
