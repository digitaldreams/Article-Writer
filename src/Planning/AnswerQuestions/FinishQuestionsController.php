<?php

namespace App\Planning\AnswerQuestions;

use App\Planning\OutlineRoundRequested;
use App\Planning\QuestionsDone;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** to=outline: "Next: outline", skips flows. Otherwise: "Show flows first". Allowed with every question skipped. */
final class FinishQuestionsController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/questions/next', name: 'planning_finish_questions', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, EventDispatcherInterface $events): Response
    {
        if (ArticleState::Questions !== $article->getState()) {
            throw new ConflictHttpException('The plan is not at the questions step.');
        }
        $toOutline = 'outline' === $request->request->getString('to');

        $em->wrapInTransaction(static function () use ($article, $events, $toOutline): void {
            $article->moveTo($toOutline ? ArticleState::Outline : ArticleState::Flows);
            $events->dispatch($toOutline ? new OutlineRoundRequested((int) $article->getId(), 1) : new QuestionsDone((int) $article->getId()));
        });

        return $this->redirectToRoute('planning_open_plan', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
