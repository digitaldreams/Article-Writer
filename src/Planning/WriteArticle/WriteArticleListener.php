<?php

namespace App\Planning\WriteArticle;

use App\Planning\PlanApproved;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class WriteArticleListener
{
    public function __construct(private EntityManagerInterface $em, private LlmJobs $jobs)
    {
    }

    #[AsEventListener]
    public function __invoke(PlanApproved $event): void
    {
        $article = $this->em->find(Article::class, $event->articleId) ?? throw new \RuntimeException('The article is gone.');

        $this->jobs->start($article, 'write_article', 'primary', static fn (int $id) => new WriteArticle($id, $event->articleId));
    }
}
