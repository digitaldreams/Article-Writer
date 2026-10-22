<?php

namespace App\Planning\FinalCheck;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The Final check page: the check notes, the refactor picked from them and its Before/After. */
final class ShowFinalCheckController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/final-check', name: 'planning_show_final_check', methods: ['GET'])]
    public function __invoke(
        Article $article,
        EntityManagerInterface $em,
        ?string $error = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $notes = [];
        foreach ($em->getRepository(CheckNote::class)->findBy(['article' => $article], ['position' => 'ASC']) as $note) {
            $notes[$note->getSection()][] = $note;
        }

        return $this->render('planning/final_check/index.html.twig', [
            'article' => $article,
            'written' => ArticleState::Written === $article->getState(),
            'notes' => $notes,
            'check_job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'check_article'], ['id' => 'DESC']),
            'refactor' => $em->getRepository(ArticleRefactor::class)->findOneBy(['article' => $article], ['id' => 'DESC']),
            'error' => $error,
        ], new Response(status: $status));
    }
}
