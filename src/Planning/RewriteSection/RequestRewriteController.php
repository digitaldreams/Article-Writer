<?php

namespace App\Planning\RewriteSection;

use App\Planning\ArticleSections;
use App\Planning\SectionRewrite;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Asks for a rewrite of one section, with the writer's instruction, from the section being edited on the Article page.
 * The section's current text comes along and is saved first, so the rewrite starts from what the writer sees.
 * A new request replaces the section's pending rewrite.
 */
final class RequestRewriteController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/article/rewrites', name: 'planning_request_rewrite', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, ArticleFolder $folder, LlmJobs $jobs): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('A section is rewritten once the article is written.');
        }

        $heading = $request->request->getString('heading');
        $instruction = trim($request->request->getString('instruction'));
        $file = $folder->read($article->getFolder(), 'article.md');
        $body = $file['body'];
        if (!\array_key_exists($heading, ArticleSections::split($body))) {
            return new Response('This section is not in the article.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('' === $instruction) {
            return new Response('Say what should change.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $asked = $heading;
        if ($request->request->has('text')) {
            try {
                ['body' => $body, 'heading' => $heading] = ArticleSections::edit($body, $heading, str_replace("\r\n", "\n", $request->request->getString('text')));
            } catch (\InvalidArgumentException $e) {
                return new Response($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $folder->write($article->getFolder(), 'article.md', $file['frontMatter'], $body);
            $article->touch();
        }
        $before = ArticleSections::split($body)[$heading];

        $em->wrapInTransaction(static function () use ($article, $em, $jobs, $heading, $asked, $instruction, $before): void {
            foreach ($em->getRepository(SectionRewrite::class)->findBy(['article' => $article, 'heading' => array_unique([$asked, $heading])]) as $stale) {
                $em->remove($stale);
            }
            $rewrite = new SectionRewrite($article, $heading, $instruction, $before);
            $em->persist($rewrite);
            $em->flush();
            $rewriteId = (int) $rewrite->getId();
            $rewrite->trackedBy($jobs->start($article, 'rewrite_section', 'primary', static fn (int $id) => new RewriteSection($id, $rewriteId)));
            $em->flush();
        });

        return $this->redirectToRoute('planning_show_article', ['id' => $article->getId(), '_fragment' => 'section-'.ArticleSections::number($body, $heading)], Response::HTTP_SEE_OTHER);
    }
}
