<?php

namespace App\Planning\FinalCheck;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Accept writes the refactored article and drops the notes it used; Reject keeps the article as it is. */
final class DecideRefactorController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/final-check/refactor/{refactorId<\d+>}', name: 'planning_decide_refactor', methods: ['POST'])]
    public function __invoke(
        Article $article,
        #[MapEntity(id: 'refactorId')] ArticleRefactor $refactor,
        Request $request,
        EntityManagerInterface $em,
        ArticleFolder $folder,
    ): Response {
        if ($refactor->getArticle() !== $article) {
            throw new NotFoundHttpException();
        }
        if (ArticleState::Written !== $article->getState() || null === $refactor->getAfter()) {
            throw new ConflictHttpException('Only a finished refactor of a written article can be decided.');
        }

        if ('accept' === $request->request->getString('decision')) {
            $file = $folder->read($article->getFolder(), 'article.md');
            if ($file['body'] !== $refactor->getBefore()) {
                return $this->forward(ShowFinalCheckController::class, ['article' => $article, 'error' => 'The article changed since you asked. Refactor again.', 'status' => Response::HTTP_CONFLICT]);
            }
            $folder->write($article->getFolder(), 'article.md', $file['frontMatter'], $refactor->getAfter());
            foreach ($em->getRepository(CheckNote::class)->findBy(['article' => $article, 'id' => $refactor->getNoteIds()]) as $note) {
                $em->remove($note);
            }
        }

        $em->remove($refactor);
        $em->flush();

        return $this->redirectToRoute('planning_show_final_check', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
