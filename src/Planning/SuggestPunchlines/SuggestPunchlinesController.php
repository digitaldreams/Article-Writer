<?php

namespace App\Planning\SuggestPunchlines;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Suggest punchlines" and "Suggest more": each click queues the next round; the writer's optional idea steers it. */
final class SuggestPunchlinesController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/share', name: 'planning_suggest_punchlines', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, LlmJobs $jobs): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('Punchlines are suggested once the article is written.');
        }
        if ($jobs->isRunning($article, 'suggest_punchlines')) {
            throw new ConflictHttpException('A round of punchlines is still coming.');
        }

        $writerIdea = trim($request->request->getString('idea')) ?: null;

        $em->wrapInTransaction(static function () use ($article, $em, $jobs, $writerIdea): void {
            $round = 1 + (int) $em->createQuery('SELECT MAX(p.round) FROM '.Punchline::class.' p WHERE p.article = :article')
                ->setParameter('article', $article)
                ->getSingleScalarResult();
            $articleId = (int) $article->getId();
            $jobs->start($article, 'suggest_punchlines', 'title', static fn (int $id) => new SuggestPunchlines($id, $articleId, $round, $writerIdea));
        });

        return $this->redirectToRoute('planning_show_punchlines', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
