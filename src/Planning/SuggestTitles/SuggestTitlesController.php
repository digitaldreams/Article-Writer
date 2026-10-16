<?php

namespace App\Planning\SuggestTitles;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Show title suggestions" and "Suggest more": each click queues the next round. */
final class SuggestTitlesController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/titles', name: 'planning_suggest_titles', methods: ['POST'])]
    public function __invoke(Article $article, EntityManagerInterface $em, LlmJobs $jobs): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('Titles are suggested once the article is written.');
        }

        $em->wrapInTransaction(static function () use ($article, $em, $jobs): void {
            $round = 1 + (int) $em->createQuery('SELECT MAX(s.round) FROM '.TitleSuggestion::class.' s WHERE s.article = :article')
                ->setParameter('article', $article)
                ->getSingleScalarResult();
            $articleId = (int) $article->getId();
            $jobs->start($article, 'suggest_titles', 'title', static fn (int $id) => new SuggestTitles($id, $articleId, $round));
        });

        return $this->redirectToRoute('planning_show_titles', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
