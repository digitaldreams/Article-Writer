<?php

namespace App\Planning\RewriteSection;

use App\Planning\ArticleSections;
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

/**
 * Accept writes the rewrite into article.md, but only while the section is still what it was when asked,
 * so a hand edit in the file is never overwritten. Reject drops the rewrite.
 */
final class DecideRewriteController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/improve/sections/{rewriteId<\d+>}', name: 'planning_decide_rewrite', methods: ['POST'])]
    public function __invoke(
        Article $article,
        #[MapEntity(id: 'rewriteId')] SectionRewrite $rewrite,
        Request $request,
        EntityManagerInterface $em,
        ArticleFolder $folder,
    ): Response {
        if ($rewrite->getArticle() !== $article) {
            throw new NotFoundHttpException();
        }
        if (ArticleState::Written !== $article->getState() || null === $rewrite->getAfter()) {
            throw new ConflictHttpException('Only a finished rewrite of a written article can be decided.');
        }

        if ('accept' === $request->request->getString('decision')) {
            $file = $folder->read($article->getFolder(), 'article.md');
            if ((ArticleSections::split($file['body'])[$rewrite->getHeading()] ?? null) !== $rewrite->getBefore()) {
                return $this->forward(ShowImproveController::class, ['article' => $article, 'error' => 'This section changed since you asked. Ask again.', 'status' => Response::HTTP_CONFLICT]);
            }
            $folder->write($article->getFolder(), 'article.md', $file['frontMatter'], ArticleSections::replace($file['body'], $rewrite->getHeading(), $rewrite->getAfter()));
        }

        $em->remove($rewrite);
        $em->flush();

        return $this->redirectToRoute('planning_improve', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
