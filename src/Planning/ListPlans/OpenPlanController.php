<?php

namespace App\Planning\ListPlans;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class OpenPlanController extends AbstractController
{
    #[Route('/plans/{id<\d+>}', name: 'planning_open_plan', methods: ['GET'])]
    public function __invoke(?Article $article): Response
    {
        $step = match ($article?->getState()) {
            ArticleState::Plan, ArticleState::Questions => 'questions',
            ArticleState::Flows => 'flows',
            ArticleState::Outline => 'outline',
            ArticleState::Finalize => 'review',
            ArticleState::Approved, ArticleState::Written => 'article',
            ArticleState::Imported, null => throw new NotFoundHttpException(),
        };

        return $this->redirect('/plans/'.$article->getId().'/'.$step);
    }
}
