<?php

namespace App\Planning\AnswerQuestions;

use App\Planning\QuestionsDone;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Next: flows". Allowed with every question skipped. */
final class FinishQuestionsController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/questions/next', name: 'planning_finish_questions', methods: ['POST'])]
    public function __invoke(Article $article, EntityManagerInterface $em, EventDispatcherInterface $events): Response
    {
        if (ArticleState::Questions !== $article->getState()) {
            throw new ConflictHttpException('The plan is not at the questions step.');
        }

        $em->wrapInTransaction(static function () use ($article, $events): void {
            $article->moveTo(ArticleState::Flows);
            $events->dispatch(new QuestionsDone((int) $article->getId()));
        });

        return $this->redirectToRoute('planning_open_plan', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
