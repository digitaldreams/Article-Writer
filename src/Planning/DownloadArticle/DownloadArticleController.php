<?php

namespace App\Planning\DownloadArticle;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Sends article.md as it is on disk (front matter included), named after the title. */
final class DownloadArticleController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/article/download', name: 'planning_download_article', methods: ['GET'])]
    public function __invoke(Article $article, ArticleFolder $folder): BinaryFileResponse
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('Only a written article can be downloaded.');
        }

        return $this->file(
            $folder->pathOf($article->getFolder(), 'article.md'),
            (ArticleFolder::slug($article->getTitle()) ?: 'article').'.md',
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );
    }
}
