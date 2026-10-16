<?php

namespace App\Planning\RejectPlan;

use App\Planning\OutlineDecision;
use App\Planning\OutlineItems;
use App\Planning\OutlineReview;
use App\Planning\OutlineRoundRequested;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * to=outline: round n+1 is generated from the current round and the reason, so hand edits carry over as its input.
 * to=flows: back to the existing flows; the answers and flows are kept, and choosing one starts the next round.
 */
final class RejectPlanController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/reject', name: 'planning_reject_plan', methods: ['POST'])]
    public function __invoke(
        Article $article,
        Request $request,
        EntityManagerInterface $em,
        EventDispatcherInterface $events,
        OutlineItems $outline,
    ): Response {
        if (ArticleState::Finalize !== $article->getState()) {
            throw new ConflictHttpException('A plan can only be rejected at the review step.');
        }
        $reason = trim($request->request->getString('reason'));
        if ('' === $reason) {
            // The review page belongs to ReviewPolish; it renders this form's error.
            return $this->forward('App\Planning\ReviewPolish\ShowReviewController', ['article' => $article, 'rejectError' => 'Say why the outline is rejected.']);
        }
        $toFlows = 'flows' === $request->request->getString('to');

        $em->wrapInTransaction(static function () use ($article, $em, $events, $outline, $reason, $toFlows): void {
            $round = $outline->latestRound($article);
            foreach ($outline->ofRound($article, $round) as $item) {
                $item->clearSuggestion();
            }
            $em->persist(new OutlineReview($article, $round, OutlineDecision::Reject, $reason));
            $article->moveTo($toFlows ? ArticleState::Flows : ArticleState::Outline);
            $em->flush();
            if (!$toFlows) {
                $events->dispatch(new OutlineRoundRequested((int) $article->getId(), $round + 1, reason: $reason));
            }
        });

        return $this->redirectToRoute('planning_open_plan', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
