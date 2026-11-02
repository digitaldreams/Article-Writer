<?php

namespace App\Planning\EditArticle;

use App\Planning\ArticleSections;
use App\Planning\SectionRewrite;
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
 * Autosave of one section's Markdown, heading line included (fetch from Stimulus). The other sections and the front matter stay as they are.
 * A new heading line renames the section: its AI suggestion follows, and X-Section-Heading tells the editor the new name.
 */
final class SaveSectionController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/article/sections', name: 'planning_save_section', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, ArticleFolder $folder): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('Only a written article can be edited.');
        }

        $heading = $request->request->getString('heading');
        $file = $folder->read($article->getFolder(), 'article.md');
        try {
            // Browsers send a textarea with \r\n; the rest of the app splits sections on \n.
            $edited = ArticleSections::edit($file['body'], $heading, str_replace("\r\n", "\n", $request->request->getString('text')));
        } catch (\InvalidArgumentException $e) {
            return new Response($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $folder->write($article->getFolder(), 'article.md', $file['frontMatter'], $edited['body']);
        foreach ($em->getRepository(SectionRewrite::class)->findBy(['article' => $article, 'heading' => $heading]) as $rewrite) {
            $rewrite->renameTo($edited['heading']);
        }
        $article->touch();
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['X-Section-Heading' => $edited['heading']]);
    }
}
