<?php

namespace App\Planning\WriteArticle;

use App\Planning\ArticleSections;
use App\Planning\SectionRewrite;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Article tab: the job while the article is written, then the article to read section by section.
 * ?section=N opens one section's Markdown editor (with Rewrite with AI).
 * A section's AI suggestion shows on it until it is accepted or rejected.
 */
final class ShowArticleController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/article', name: 'planning_show_article', methods: ['GET'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, ArticleFolder $folder): Response
    {
        $written = ArticleState::Written === $article->getState();
        $file = $written ? $folder->read($article->getFolder(), 'article.md') : null;
        $rewrites = [];
        foreach ($written ? $em->getRepository(SectionRewrite::class)->findBy(['article' => $article]) : [] as $rewrite) {
            $rewrites[$rewrite->getHeading()] = $rewrite;
        }

        return $this->render('planning/write_article/index.html.twig', [
            'article' => $article,
            'job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'write_article'], ['id' => 'DESC']),
            'path' => $written ? $folder->pathOf($article->getFolder(), 'article.md') : null,
            'subtitle' => $file['frontMatter']['subtitle'] ?? null,
            'body' => $file['body'] ?? null,
            'head' => null === $file ? '' : ArticleSections::head($file['body']),
            'sections' => null === $file ? [] : ArticleSections::sections($file['body']),
            'rewrites' => $rewrites,
            'editing_section' => $request->query->getInt('section'),
        ]);
    }
}
