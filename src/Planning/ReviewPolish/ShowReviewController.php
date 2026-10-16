<?php

namespace App\Planning\ReviewPolish;

use App\Planning\OutlineCheckup;
use App\Planning\OutlineItems;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowReviewController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/review', name: 'planning_show_review', methods: ['GET'])]
    public function __invoke(Article $article, EntityManagerInterface $em, OutlineItems $outline, ?string $rejectError = null, ?string $approveError = null): Response
    {
        $round = $outline->latestRound($article);

        return $this->render('planning/review_polish/index.html.twig', [
            'article' => $article,
            'round' => $round,
            'items' => $outline->ofRound($article, $round),
            'checkup' => $em->getRepository(OutlineCheckup::class)->findOneBy(['article' => $article, 'round' => $round]),
            'job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'final_checkup'], ['id' => 'DESC']),
            'reject_error' => $rejectError,
            'approve_error' => $approveError,
        ], new Response(status: match (true) {
            null !== $approveError => 500,
            null !== $rejectError => 422,
            default => 200,
        }));
    }
}
