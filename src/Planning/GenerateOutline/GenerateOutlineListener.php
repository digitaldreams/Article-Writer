<?php

namespace App\Planning\GenerateOutline;

use App\Planning\OutlineRoundRequested;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class GenerateOutlineListener
{
    public function __construct(private EntityManagerInterface $em, private LlmJobs $jobs)
    {
    }

    #[AsEventListener]
    public function __invoke(OutlineRoundRequested $event): void
    {
        $article = $this->em->find(Article::class, $event->articleId) ?? throw new \RuntimeException('The article is gone.');

        $this->jobs->start($article, 'generate_outline', 'primary', static fn (int $id) => new GenerateOutline($id, $event->articleId, $event->round, $event->instruction));
    }
}
