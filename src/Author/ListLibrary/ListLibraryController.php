<?php

namespace App\Author\ListLibrary;

use App\Author\WritingNote;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** The author's published articles: everything imported, newest first. */
final class ListLibraryController extends AbstractController
{
    #[Route('/author/library', name: 'author_list_library', methods: ['GET'])]
    public function __invoke(EntityManagerInterface $em): Response
    {
        /** @var list<array{0: Article, analyzed: bool}> $rows */
        $rows = $em->createQuery('SELECT a, (CASE WHEN EXISTS (SELECT n.id FROM '.WritingNote::class.' n WHERE n.article = a) THEN true ELSE false END) AS analyzed, COALESCE(a.publishedAt, a.createdAt) AS HIDDEN published
            FROM '.Article::class.' a WHERE a.state = :imported ORDER BY published DESC, a.id DESC')
            ->setParameter('imported', ArticleState::Imported)->getResult();

        return $this->render('author/list_library/index.html.twig', [
            'rows' => array_map(static fn (array $row): array => ['article' => $row[0], 'analyzed' => (bool) $row['analyzed']], $rows),
        ]);
    }

    #[Route('/author/library/{id<\d+>}/delete', name: 'author_delete_library_article', methods: ['POST'])]
    public function delete(Article $article, EntityManagerInterface $em, ArticleFolder $folder): Response
    {
        if (ArticleState::Imported !== $article->getState()) {
            throw $this->createNotFoundException('Only imported articles are in the library.');
        }
        // A queued message would run against a deleted job.
        if ($em->getRepository(LlmJob::class)->count(['article' => $article, 'status' => [LlmJobStatus::Queued, LlmJobStatus::Working]]) > 0) {
            throw new ConflictHttpException('The article is still being analyzed.');
        }

        $name = $article->getFolder();
        // Notes and job rows go with it (ON DELETE CASCADE).
        $em->remove($article);
        $em->flush();
        $folder->remove($name);

        return $this->redirectToRoute('author_list_library', status: Response::HTTP_SEE_OTHER);
    }
}
