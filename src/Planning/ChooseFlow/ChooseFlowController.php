<?php

namespace App\Planning\ChooseFlow;

use App\Planning\FlowOption;
use App\Planning\OutlineItems;
use App\Planning\OutlineRoundRequested;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Choosing a flow starts the next outline round: round 1, or n+1 after a reject back to flows. */
final class ChooseFlowController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/flows/{flowId<\d+>}', name: 'planning_choose_flow', methods: ['POST'])]
    public function __invoke(
        Article $article,
        #[MapEntity(id: 'flowId')] FlowOption $flow,
        EntityManagerInterface $em,
        EventDispatcherInterface $events,
        OutlineItems $outline,
    ): Response {
        if ($flow->getArticle() !== $article) {
            throw new NotFoundHttpException();
        }
        if (ArticleState::Flows !== $article->getState()) {
            throw new ConflictHttpException('A flow can only be chosen at the flows step.');
        }

        $em->wrapInTransaction(static function () use ($article, $flow, $em, $events, $outline): void {
            foreach ($em->getRepository(FlowOption::class)->findBy(['article' => $article]) as $option) {
                $option->unchoose();
            }
            $flow->choose();
            $article->moveTo(ArticleState::Outline);
            $em->flush();
            $events->dispatch(new OutlineRoundRequested((int) $article->getId(), $outline->latestRound($article) + 1));
        });

        return $this->redirectToRoute('planning_open_plan', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
