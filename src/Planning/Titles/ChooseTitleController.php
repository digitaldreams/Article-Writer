<?php

namespace App\Planning\Titles;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The chosen pair replaces the article title, the article.md front matter title and subtitle,
 * and the "# " title + "## " subtitle lines at the top of the body (added when the body has none yet).
 */
final class ChooseTitleController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/titles/choose', name: 'planning_choose_title', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, ArticleFolder $folder): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('A title is chosen once the article is written.');
        }
        $suggestion = $em->getRepository(TitleSuggestion::class)->findOneBy(['article' => $article, 'id' => $request->getPayload()->getInt('suggestion')]);
        if (null === $suggestion) {
            return new Response('Pick one of the suggested titles.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $title = $suggestion->getTitle();
        $subtitle = $suggestion->getSubtitle();

        $file = $folder->read($article->getFolder(), 'article.md');
        $headings = "# {$title}\n## {$subtitle}\n";
        $body = str_starts_with($file['body'], '# ')
            ? preg_replace_callback('/\A# .*\n(## .*\n)?/', static fn () => $headings, $file['body'], 1) ?? $file['body']
            : $headings."\n".$file['body'];
        $folder->write($article->getFolder(), 'article.md', array_replace($file['frontMatter'], ['title' => $title, 'subtitle' => $subtitle]), $body);

        $article->setTitle($title);
        $em->flush();

        return $this->redirectToRoute('planning_show_titles', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
