<?php

namespace App\Planning\EditOutline;

use App\Planning\OutlineItems;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowOutlineController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/outline', name: 'planning_show_outline', methods: ['GET'])]
    public function __invoke(Article $article, EntityManagerInterface $em, OutlineItems $outline, ?string $directionError = null): Response
    {
        return $this->render('planning/edit_outline/index.html.twig', [
            'article' => $article,
            'round' => $outline->latestRound($article),
            'items' => $outline->latest($article),
            'job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'generate_outline'], ['id' => 'DESC']),
            'direction_error' => $directionError,
        ], new Response(status: null === $directionError ? 200 : 422));
    }
}
