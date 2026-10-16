<?php

namespace App\Planning\AskQuestions;

use App\Planning\ReferencesSettled;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Runs in the transaction that fired ReferencesSettled: plan → questions and the personas job commit together. */
final readonly class AskQuestionsListener
{
    public function __construct(private EntityManagerInterface $em, private LlmJobs $jobs)
    {
    }

    #[AsEventListener]
    public function __invoke(ReferencesSettled $event): void
    {
        $article = $this->em->find(Article::class, $event->articleId) ?? throw new \RuntimeException('The article is gone.');
        $article->moveTo(ArticleState::Questions);

        $articleId = $event->articleId;
        $this->jobs->start($article, 'ask_questions', 'primary', static fn (int $id) => new AskQuestions($id, $articleId));
    }
}
