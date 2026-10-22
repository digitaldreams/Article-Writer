<?php

namespace App\Planning\RewriteSection;

use App\Planning\ArticleSections;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The Improve page: sections with their rewrite forms and pending rewrites. */
final class ShowImproveController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/improve', name: 'planning_improve', methods: ['GET'])]
    public function __invoke(
        Article $article,
        EntityManagerInterface $em,
        ArticleFolder $folder,
        ?string $error = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $written = ArticleState::Written === $article->getState();
        $rewrites = [];
        foreach ($em->getRepository(SectionRewrite::class)->findBy(['article' => $article]) as $rewrite) {
            $rewrites[$rewrite->getHeading()] = $rewrite;
        }

        return $this->render('planning/rewrite_section/index.html.twig', [
            'article' => $article,
            'written' => $written,
            'sections' => $written ? ArticleSections::split($folder->read($article->getFolder(), 'article.md')['body']) : [],
            'rewrites' => $rewrites,
            'error' => $error,
        ], new Response(status: $status));
    }
}
