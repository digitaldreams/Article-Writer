<?php

namespace App\Planning\AnswerQuestions;

use App\Planning\Question;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Autosave of one answer (fetch from Stimulus), or Skip (plain form submit; q is the question to show next). */
final class SaveAnswerController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/questions/{questionId<\d+>}', name: 'planning_save_answer', methods: ['POST'])]
    public function __invoke(
        Article $article,
        #[MapEntity(id: 'questionId')] Question $question,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if ($question->getArticle() !== $article) {
            throw new NotFoundHttpException();
        }
        if (ArticleState::Questions !== $article->getState()) {
            throw new ConflictHttpException('Answers can only change while the plan is at the questions step.');
        }

        // ponytail: an option that itself contains "; " won't show as checked again; rare in short options.
        $answer = $request->request->all()['answer'] ?? '';
        $request->request->has('skip') ? $question->skip() : $question->saveAnswer(\is_array($answer) ? implode('; ', array_filter($answer, 'is_string')) : (\is_string($answer) ? $answer : ''));
        $em->flush();

        return $request->request->has('skip')
            ? $this->redirectToRoute('planning_answer_questions', ['id' => $article->getId(), 'q' => $request->request->getInt('q') ?: null], Response::HTTP_SEE_OTHER)
            : new Response(status: Response::HTTP_NO_CONTENT);
    }
}
