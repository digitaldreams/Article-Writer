<?php

namespace App\Planning\RewriteSection;

use App\Planning\ArticleSections;
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
 * Asks for a rewrite of one section, with the writer's instruction.
 * A new request replaces the section's pending rewrite.
 */
final class RequestRewriteController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/improve/sections', name: 'planning_request_rewrite', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, ArticleFolder $folder, LlmJobs $jobs): Response
    {
        if (ArticleState::Written !== $article->getState()) {
            throw new ConflictHttpException('A section is rewritten once the article is written.');
        }

        $heading = $request->request->getString('heading');
        $instruction = trim($request->request->getString('instruction'));
        $sections = ArticleSections::split($folder->read($article->getFolder(), 'article.md')['body']);
        $error = match (true) {
            !\array_key_exists($heading, $sections) => 'This section is not in the article.',
            '' === $instruction => 'Say what should change.',
            default => null,
        };
        if (null !== $error) {
            return $this->forward(ShowImproveController::class, ['article' => $article, 'error' => $error, 'status' => Response::HTTP_UNPROCESSABLE_ENTITY]);
        }

        $em->wrapInTransaction(static function () use ($article, $em, $jobs, $heading, $instruction, $sections): void {
            foreach ($em->getRepository(SectionRewrite::class)->findBy(['article' => $article, 'heading' => $heading]) as $old) {
                $em->remove($old);
            }
            $rewrite = new SectionRewrite($article, $heading, $instruction, $sections[$heading]);
            $em->persist($rewrite);
            $em->flush();
            $rewriteId = (int) $rewrite->getId();
            $rewrite->trackedBy($jobs->start($article, 'rewrite_section', 'primary', static fn (int $id) => new RewriteSection($id, $rewriteId)));
            $em->flush();
        });

        return $this->redirectToRoute('planning_improve', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
