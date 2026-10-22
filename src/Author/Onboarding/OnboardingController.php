<?php

namespace App\Author\Onboarding;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The wizard a new author goes through before the app: import → bio → analyze → approve the writing profile.
 * The step is worked out from the author's data, so leaving mid-step lands them back on it. The forms post to the
 * existing Author slices; their redirects pass RedirectToOnboarding, which sends the author back here.
 */
final class OnboardingController extends AbstractController
{
    private const IMPORT = ['import_file'];
    private const ANALYSIS = ['extract_writing_notes', 'merge_writing_profile'];
    private const OPEN = [LlmJobStatus::Queued, LlmJobStatus::Working];

    #[Route('/welcome', name: 'author_onboarding', methods: ['GET'])]
    public function __invoke(EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        if ($user->isOnboarded()) {
            return $this->redirectToRoute('planning_list_plans');
        }

        $step = $this->step($em, $user);

        return $this->render("author/onboarding/$step.html.twig", [
            'step' => $step,
            'imports_open' => $this->jobCount($em, self::IMPORT, open: true),
            'imported' => $em->getRepository(Article::class)->count(['state' => ArticleState::Imported]),
            'analysis_jobs' => 'profile' === $step ? $this->analysisJobs($em) : [],
            'profile_ready' => $this->profileReady($em, $user),
        ]);
    }

    #[Route('/welcome/approve', name: 'author_onboarding_approve', methods: ['POST'])]
    public function approve(EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        if (!$user->isOnboarded()) {
            if ('profile' !== $this->step($em, $user) || !$this->profileReady($em, $user)) {
                throw new ConflictHttpException('The writing profile is not ready yet.');
            }
            $user->markOnboarded();
            $em->flush();
        }

        return $this->redirectToRoute('planning_list_plans', status: Response::HTTP_SEE_OTHER);
    }

    /** @return 'import'|'bio'|'analyze'|'profile' */
    private function step(EntityManagerInterface $em, User $user): string
    {
        return match (true) {
            // No upload yet, or every import failed: the author has nothing to analyze.
            0 === $this->jobCount($em, self::IMPORT),
            0 === $this->jobCount($em, self::IMPORT, open: true) && 0 === $em->getRepository(Article::class)->count(['state' => ArticleState::Imported]) => 'import',
            '' === $user->getLanguageLevel() => 'bio',
            0 === $this->jobCount($em, self::ANALYSIS) => 'analyze',
            default => 'profile',
        };
    }

    /** Every note has finished and the merge has written a profile. Failed notes don't block: the merge runs on the rest. */
    private function profileReady(EntityManagerInterface $em, User $user): bool
    {
        return [] !== $user->getWritingProfile()['structure'] && 0 === $this->jobCount($em, self::ANALYSIS, open: true);
    }

    /** @param list<string> $steps */
    private function jobCount(EntityManagerInterface $em, array $steps, bool $open = false): int
    {
        return $em->getRepository(LlmJob::class)->count(['step' => $steps] + ($open ? ['status' => self::OPEN] : []));
    }

    /** @return list<array{label: string, job: LlmJob}> */
    private function analysisJobs(EntityManagerInterface $em): array
    {
        /** @var list<LlmJob> $jobs */
        $jobs = $em->getRepository(LlmJob::class)->createQueryBuilder('j')
            ->leftJoin('j.article', 'a')->addSelect('a')
            ->where('j.step IN (:steps)')->setParameter('steps', self::ANALYSIS)
            ->orderBy('j.id', 'DESC')->setMaxResults(100)
            ->getQuery()->getResult();

        return array_map(static fn (LlmJob $job): array => [
            'label' => 'extract_writing_notes' === $job->getStep() ? ($job->getArticle()?->getTitle() ?? '') : 'Merge into one profile',
            'job' => $job,
        ], $jobs);
    }
}
