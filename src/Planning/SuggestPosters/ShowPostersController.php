<?php

namespace App\Planning\SuggestPosters;

use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowPostersController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/poster', name: 'planning_show_posters', methods: ['GET'])]
    public function __invoke(Article $article, EntityManagerInterface $em, PosterImage $image, ?string $ideaError = null): Response
    {
        $ideas = $em->getRepository(PosterIdea::class)->findBy(['article' => $article], ['id' => 'ASC']);
        $drawn = [];
        foreach ($ideas as $idea) {
            // The file time keeps the browser from showing the old picture after "Generate again".
            $drawn[$idea->getId()] = is_file($image->pathOf($idea)) ? filemtime($image->pathOf($idea)) : null;
        }

        return $this->render('planning/suggest_posters/index.html.twig', [
            'article' => $article,
            'job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'suggest_posters'], ['id' => 'DESC']),
            'draw_job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'generate_poster'], ['id' => 'DESC']),
            'can_draw' => $image->canDraw(),
            'ideas' => $ideas,
            'drawn' => $drawn,
            'idea_error' => $ideaError,
        ], new Response(status: null === $ideaError ? 200 : 422));
    }
}
