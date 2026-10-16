<?php

namespace App\Planning\SuggestPosters;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Show poster ideas", "Suggest more" and "Share your idea": each queues the next round; a shared idea steers that round. */
final class SuggestPostersController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/poster', name: 'planning_suggest_posters', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, LlmJobs $jobs): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('Poster ideas come once the article is written.');
        }
        $writerIdea = $request->request->has('idea') ? trim($request->request->getString('idea')) : null;
        if ('' === $writerIdea) {
            return $this->forward(ShowPostersController::class, ['article' => $article, 'ideaError' => 'Write your idea first.']);
        }

        $em->wrapInTransaction(static function () use ($article, $em, $jobs, $writerIdea): void {
            $round = 1 + (int) $em->createQuery('SELECT MAX(p.round) FROM '.PosterIdea::class.' p WHERE p.article = :article')
                ->setParameter('article', $article)
                ->getSingleScalarResult();
            $articleId = (int) $article->getId();
            $jobs->start($article, 'suggest_posters', 'poster', static fn (int $id) => new SuggestPosters($id, $articleId, $round, $writerIdea));
        });

        return $this->redirectToRoute('planning_show_posters', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
