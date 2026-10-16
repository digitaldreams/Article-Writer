<?php

namespace App\Planning\Brainstorm;

use App\Planning\BrainstormDone;
use App\Planning\Reference;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Next: questions". Waits until every reference is done or unreadable. */
final class FinishBrainstormController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/brainstorm/next', name: 'planning_finish_brainstorm', methods: ['POST'])]
    public function __invoke(Article $article, EntityManagerInterface $em, EventDispatcherInterface $events): Response
    {
        if (ArticleState::Plan !== $article->getState()) {
            throw new ConflictHttpException('The plan is not at the brainstorming step.');
        }
        if ($em->getRepository(Reference::class)->count(['article' => $article, 'status' => ReferenceStatus::Pending]) > 0) {
            throw new ConflictHttpException('Some references are still being read.');
        }

        $em->wrapInTransaction(static function () use ($article, $events): void {
            $events->dispatch(new BrainstormDone((int) $article->getId()));
        });

        return $this->redirectToRoute('planning_answer_questions', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
