<?php

namespace App\Planning\ReviewArticle;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Get reader review": the reader persona reads the written article. */
final class ReviewArticleController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/improve/review', name: 'planning_review_article', methods: ['POST'])]
    public function __invoke(Article $article, EntityManagerInterface $em, LlmJobs $jobs): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('The article is reviewed once it is written.');
        }

        $em->wrapInTransaction(static function () use ($article, $jobs): void {
            $articleId = (int) $article->getId();
            $jobs->start($article, 'review_article', 'primary', static fn (int $id) => new ReviewArticle($id, $articleId));
        });

        return $this->redirectToRoute('planning_improve', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
