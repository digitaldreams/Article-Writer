<?php

namespace App\Planning\FinalCheck;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Refactor with these": the whole article is rewritten from the ticked check notes. A new request replaces the old refactor. */
final class RequestRefactorController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/final-check/refactor', name: 'planning_request_refactor', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, ArticleFolder $folder, LlmJobs $jobs): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('The article is refactored once it is written.');
        }
        $notes = $em->getRepository(CheckNote::class)->findBy(['article' => $article, 'id' => array_map('intval', $request->request->all('notes'))]);
        if ([] === $notes) {
            return $this->forward(ShowFinalCheckController::class, ['article' => $article, 'error' => 'Tick at least one note.', 'status' => Response::HTTP_UNPROCESSABLE_ENTITY]);
        }

        $em->wrapInTransaction(static function () use ($article, $em, $folder, $jobs, $notes): void {
            foreach ($em->getRepository(ArticleRefactor::class)->findBy(['article' => $article]) as $old) {
                $em->remove($old);
            }
            $noteIds = array_map(static fn (CheckNote $n) => (int) $n->getId(), $notes);
            $refactor = new ArticleRefactor($article, $folder->read($article->getFolder(), 'article.md')['body'], $noteIds);
            $em->persist($refactor);
            $em->flush();
            $refactorId = (int) $refactor->getId();
            $refactor->trackedBy($jobs->start($article, 'refactor_article', 'check', static fn (int $id) => new RefactorArticle($id, $refactorId)));
            $em->flush();
        });

        return $this->redirectToRoute('planning_show_final_check', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
