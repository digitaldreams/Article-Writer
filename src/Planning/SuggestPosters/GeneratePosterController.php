<?php

namespace App\Planning\SuggestPosters;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Generate poster" on one idea. */
final class GeneratePosterController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/poster/{ideaId<\d+>}', name: 'planning_generate_poster', methods: ['POST'])]
    public function __invoke(Article $article, int $ideaId, EntityManagerInterface $em, LlmJobs $jobs): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('Posters are drawn once the article is written.');
        }
        $em->getRepository(PosterIdea::class)->findOneBy(['article' => $article, 'id' => $ideaId]) ?? throw new NotFoundHttpException();

        $em->wrapInTransaction(static fn () => $jobs->start($article, 'generate_poster', 'poster_image', static fn (int $id) => new GeneratePoster($id, $ideaId)));

        return $this->redirectToRoute('planning_show_posters', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
