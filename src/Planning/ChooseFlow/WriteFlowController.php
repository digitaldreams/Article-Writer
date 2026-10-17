<?php

namespace App\Planning\ChooseFlow;

use App\Planning\FlowOption;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Use my flow": the author's own flow becomes a flow option and is chosen at once. */
final class WriteFlowController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/flows', name: 'planning_write_flow', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, FlowChoice $choice): Response
    {
        if (ArticleState::Flows !== $article->getState()) {
            throw new ConflictHttpException('A flow can only be chosen at the flows step.');
        }
        $description = trim($request->request->getString('description'));
        if ('' === $description) {
            throw new UnprocessableEntityHttpException('The flow is empty.');
        }

        $choice->choose(new FlowOption($article, $description));

        return $this->redirectToRoute('planning_open_plan', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
