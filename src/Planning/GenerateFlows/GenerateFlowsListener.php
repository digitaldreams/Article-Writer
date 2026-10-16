<?php

namespace App\Planning\GenerateFlows;

use App\Planning\FlowOption;
use App\Planning\QuestionsDone;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Coming back to the flows step keeps the flows that exist; only the first visit generates them. */
final readonly class GenerateFlowsListener
{
    public function __construct(private EntityManagerInterface $em, private LlmJobs $jobs)
    {
    }

    #[AsEventListener]
    public function __invoke(QuestionsDone $event): void
    {
        $article = $this->em->find(Article::class, $event->articleId) ?? throw new \RuntimeException('The article is gone.');
        if ($this->em->getRepository(FlowOption::class)->count(['article' => $article]) > 0) {
            return;
        }

        $articleId = $event->articleId;
        $this->jobs->start($article, 'generate_flows', 'primary', static fn (int $id) => new GenerateFlows($id, $articleId));
    }
}
