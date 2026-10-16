<?php

namespace App\Planning\FinalCheckup;

use App\Planning\FinalizeRequested;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class FinalCheckupListener
{
    public function __construct(private EntityManagerInterface $em, private LlmJobs $jobs)
    {
    }

    #[AsEventListener]
    public function __invoke(FinalizeRequested $event): void
    {
        $article = $this->em->find(Article::class, $event->articleId) ?? throw new \RuntimeException('The article is gone.');

        $this->jobs->start($article, 'final_checkup', 'primary', static fn (int $id) => new FinalCheckup($id, $event->articleId, $event->round));
    }
}
