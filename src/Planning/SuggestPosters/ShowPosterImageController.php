<?php

namespace App\Planning\SuggestPosters;

use App\Shared\Article\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ShowPosterImageController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/poster/{ideaId<\d+>}.png', name: 'planning_show_poster_image', methods: ['GET'])]
    public function __invoke(Article $article, int $ideaId, EntityManagerInterface $em, PosterImage $image): BinaryFileResponse
    {
        $idea = $em->getRepository(PosterIdea::class)->findOneBy(['article' => $article, 'id' => $ideaId]) ?? throw new NotFoundHttpException();
        $path = $image->pathOf($idea);

        return is_file($path) ? new BinaryFileResponse($path) : throw new NotFoundHttpException();
    }
}
