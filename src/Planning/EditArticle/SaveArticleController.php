<?php

namespace App\Planning\EditArticle;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Autosave of the hand-edited body (fetch from Stimulus). The front matter stays as it is. */
final class SaveArticleController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/article', name: 'planning_save_article', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, ArticleFolder $folder): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('Only a written article can be edited.');
        }

        $file = $folder->read($article->getFolder(), 'article.md');
        // Browsers send a textarea with \r\n; the rest of the app splits sections on \n.
        $folder->write($article->getFolder(), 'article.md', $file['frontMatter'], str_replace("\r\n", "\n", $request->request->getString('body')));
        $article->touch();
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
