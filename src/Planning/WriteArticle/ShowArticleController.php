<?php

namespace App\Planning\WriteArticle;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowArticleController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/article', name: 'planning_show_article', methods: ['GET'])]
    public function __invoke(Article $article, EntityManagerInterface $em, ArticleFolder $folder): Response
    {
        $written = ArticleState::Written === $article->getState();
        $file = $written ? $folder->read($article->getFolder(), 'article.md') : null;

        return $this->render('planning/write_article/index.html.twig', [
            'article' => $article,
            'job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'write_article'], ['id' => 'DESC']),
            'path' => $written ? $folder->pathOf($article->getFolder(), 'article.md') : null,
            'subtitle' => $file['frontMatter']['subtitle'] ?? null,
            'body' => $file['body'] ?? null,
        ]);
    }
}
