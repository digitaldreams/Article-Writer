<?php

namespace App\Author\AnalyzeWriting;

use App\Author\WritingNote;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobs;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\User\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** "Analyze my writing": one notes job per library article without a note; the merge follows the last note (SettleWritingProfile). */
final class AnalyzeWritingController extends AbstractController
{
    #[Route('/author/profile/analyze', name: 'author_analyze_writing', methods: ['POST'])]
    public function __invoke(EntityManagerInterface $em, LlmJobs $jobs, #[CurrentUser] User $user): Response
    {
        $em->wrapInTransaction(static function () use ($em, $jobs, $user): void {
            // The user row lock makes two clicks take turns (SettleWritingProfile takes it too): the second sees the jobs of the first.
            $em->find(User::class, $user->getId(), LockMode::PESSIMISTIC_WRITE);
            if ($em->getRepository(LlmJob::class)->count(['step' => SettleWritingProfile::STEPS, 'status' => [LlmJobStatus::Queued, LlmJobStatus::Working]]) > 0) {
                throw new ConflictHttpException('The analysis is already running.');
            }
            // An article still importing would be left out of the profile.
            if ($em->getRepository(LlmJob::class)->count(['step' => 'import_file', 'status' => [LlmJobStatus::Queued, LlmJobStatus::Working]]) > 0) {
                throw new ConflictHttpException('Your imports are still running.');
            }
            if (0 === $em->getRepository(Article::class)->count(['state' => ArticleState::Imported])) {
                throw new ConflictHttpException('The library is empty: import your articles first.');
            }

            /** @var list<Article> $missing */
            $missing = $em->createQuery('SELECT a FROM '.Article::class.' a WHERE a.state = :imported
                AND NOT EXISTS (SELECT n.id FROM '.WritingNote::class.' n WHERE n.article = a) ORDER BY a.id')
                ->setParameter('imported', ArticleState::Imported)->getResult();
            foreach ($missing as $article) {
                $articleId = (int) $article->getId();
                $jobs->start($article, 'extract_writing_notes', 'secondary', static fn (int $id) => new ExtractWritingNotes($id, $articleId));
            }
            // Every article has its note: merge again now (e.g. after the author's hand edits).
            if ([] === $missing) {
                $jobs->start(null, 'merge_writing_profile', 'secondary', static fn (int $id) => new MergeWritingProfile($id), $user);
            }
        });

        return $this->redirectToRoute('author_edit_writing_profile', status: Response::HTTP_SEE_OTHER);
    }
}
