<?php

namespace App\Planning\Brainstorm;

use App\Planning\BrainstormDone;
use App\Planning\OutlineRoundRequested;
use App\Planning\Reference;
use App\Planning\ReferenceStatus;
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
 * to=outline: "Next: outline", skips questions; the outline is made from the idea and references.
 * Otherwise: "Ask me questions first". Both wait until every reference is done or unreadable.
 */
final class FinishBrainstormController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/brainstorm/next', name: 'planning_finish_brainstorm', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, EventDispatcherInterface $events): Response
    {
        if (ArticleState::Plan !== $article->getState()) {
            throw new ConflictHttpException('The plan is not at the brainstorming step.');
        }
        if ($em->getRepository(Reference::class)->count(['article' => $article, 'status' => ReferenceStatus::Pending]) > 0) {
            throw new ConflictHttpException('Some references are still being read.');
        }
        $toOutline = 'outline' === $request->request->getString('to');

        $em->wrapInTransaction(static function () use ($article, $events, $toOutline): void {
            if ($toOutline) {
                $article->moveTo(ArticleState::Outline);
                $events->dispatch(new OutlineRoundRequested((int) $article->getId(), 1));
            } else {
                $events->dispatch(new BrainstormDone((int) $article->getId()));
            }
        });

        return $this->redirectToRoute($toOutline ? 'planning_show_outline' : 'planning_answer_questions', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
