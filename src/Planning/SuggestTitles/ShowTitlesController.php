<?php

namespace App\Planning\SuggestTitles;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowTitlesController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/titles', name: 'planning_show_titles', methods: ['GET'])]
    public function __invoke(Article $article, EntityManagerInterface $em, ArticleFolder $folder): Response
    {
        return $this->render('planning/suggest_titles/index.html.twig', [
            'article' => $article,
            'subtitle' => ArticleState::Written === $article->getState() ? $folder->read($article->getFolder(), 'article.md')['frontMatter']['subtitle'] ?? null : null,
            'job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'suggest_titles'], ['id' => 'DESC']),
            'suggestions' => $em->getRepository(TitleSuggestion::class)->findBy(['article' => $article], ['id' => 'ASC']),
        ]);
    }
}
