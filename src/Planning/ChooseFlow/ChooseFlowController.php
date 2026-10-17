<?php

namespace App\Planning\ChooseFlow;

use App\Planning\FlowOption;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Choose this flow", with the text as the author edited it. */
final class ChooseFlowController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/flows/{flowId<\d+>}', name: 'planning_choose_flow', methods: ['POST'])]
    public function __invoke(Article $article, #[MapEntity(id: 'flowId')] FlowOption $flow, Request $request, FlowChoice $choice): Response
    {
        if ($flow->getArticle() !== $article) {
            throw new NotFoundHttpException();
        }
        if (ArticleState::Flows !== $article->getState()) {
            throw new ConflictHttpException('A flow can only be chosen at the flows step.');
        }
        $description = trim($request->request->getString('description'));
        if ('' === $description) {
            throw new UnprocessableEntityHttpException('The flow is empty.');
        }

        $flow->rewrite($description);
        $choice->choose($flow);

        return $this->redirectToRoute('planning_open_plan', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
