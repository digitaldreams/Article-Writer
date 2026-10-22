<?php

namespace App\Planning\SuggestPunchlines;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowPunchlinesController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/share', name: 'planning_show_punchlines', methods: ['GET'])]
    public function __invoke(Article $article, EntityManagerInterface $em, ArticleFolder $folder): Response
    {
        return $this->render('planning/suggest_punchlines/index.html.twig', [
            'article' => $article,
            'job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'suggest_punchlines'], ['id' => 'DESC']),
            'punchlines' => $em->getRepository(Punchline::class)->findBy(['article' => $article], ['id' => 'ASC']),
            'topics' => ArticleState::Written === $article->getState() ? $folder->read($article->getFolder(), 'article.md')['frontMatter']['topics'] ?? [] : [],
        ]);
    }
}
