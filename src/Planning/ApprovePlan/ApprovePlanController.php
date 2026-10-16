<?php

namespace App\Planning\ApprovePlan;

use App\Planning\OutlineDecision;
use App\Planning\OutlineItems;
use App\Planning\OutlineReview;
use App\Planning\PlanApproved;
use App\Planning\PlanInput;
use App\Planning\Question;
use App\Planning\Reference;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** The md files are written first; only then the state commits. A failed write commits nothing. */
final class ApprovePlanController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/approve', name: 'planning_approve_plan', methods: ['POST'])]
    public function __invoke(
        Article $article,
        EntityManagerInterface $em,
        EventDispatcherInterface $events,
        OutlineItems $outline,
        PlanFiles $files,
    ): Response {
        if (ArticleState::Finalize !== $article->getState()) {
            throw new ConflictHttpException('A plan can only be approved at the review step.');
        }

        $round = $outline->latestRound($article);
        $items = $outline->ofRound($article, $round);
        try {
            $files->write(
                $article,
                $em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.'),
                $em->getRepository(Reference::class)->findBy(['article' => $article], ['id' => 'ASC']),
                $items,
                $em->getRepository(Question::class)->findBy(['article' => $article], ['position' => 'ASC']),
                new \DateTimeImmutable(),
            );
        } catch (IOExceptionInterface $e) {
            // The review page belongs to ReviewPolish; it renders this error.
            return $this->forward('App\Planning\ReviewPolish\ShowReviewController', ['article' => $article, 'approveError' => 'The plan files could not be written: '.$e->getMessage()]);
        }

        $em->wrapInTransaction(static function () use ($article, $em, $events, $items, $round): void {
            foreach ($items as $item) {
                $item->clearSuggestion();
            }
            $em->persist(new OutlineReview($article, $round, OutlineDecision::Approve));
            $article->moveTo(ArticleState::Approved);
            $em->flush();
            $events->dispatch(new PlanApproved((int) $article->getId()));
        });

        return $this->redirectToRoute('planning_open_plan', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
