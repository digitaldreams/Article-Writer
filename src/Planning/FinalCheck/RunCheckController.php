<?php

namespace App\Planning\FinalCheck;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Run final check": the check model reads the whole written article. */
final class RunCheckController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/final-check', name: 'planning_run_final_check', methods: ['POST'])]
    public function __invoke(Article $article, EntityManagerInterface $em, LlmJobs $jobs): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('The final check runs once the article is written.');
        }

        $em->wrapInTransaction(static function () use ($article, $jobs): void {
            $articleId = (int) $article->getId();
            $jobs->start($article, 'check_article', 'check', static fn (int $id) => new CheckArticle($id, $articleId));
        });

        return $this->redirectToRoute('planning_show_final_check', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
