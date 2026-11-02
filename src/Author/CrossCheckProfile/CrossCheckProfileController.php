<?php

namespace App\Author\CrossCheckProfile;

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

/** "Cross Check my Structure and Voice": a frame on /author/profile. It reloads alone, so unsaved edits in the form survive. */
final class CrossCheckProfileController extends AbstractController
{
    private const STEP = 'cross_check_profile';

    #[Route('/author/profile/cross-check', name: 'author_cross_check_profile', methods: ['GET'])]
    public function panel(EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        return $this->render('author/cross_check_profile/_panel.html.twig', [
            'job' => $em->getRepository(LlmJob::class)->findOneBy(['step' => self::STEP], ['id' => 'DESC']),
            'complete' => $user->hasCompleteWritingProfile(),
            'points' => $user->getWritingProfileCheck(),
            'fields' => [...User::WRITING_STRUCTURE, ...User::WRITING_VOICE],
            'general' => CrossCheckProfileHandler::GENERAL,
        ]);
    }

    #[Route('/author/profile/cross-check', name: 'author_start_cross_check', methods: ['POST'])]
    public function start(EntityManagerInterface $em, LlmJobs $jobs, #[CurrentUser] User $user): Response
    {
        $em->wrapInTransaction(static function () use ($em, $jobs, $user): void {
            // The user row lock makes two clicks take turns: the second sees the job of the first.
            $em->find(User::class, $user->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$user->hasCompleteWritingProfile()) {
                throw new ConflictHttpException('Fill and save every field first.');
            }
            if ($em->getRepository(LlmJob::class)->count(['step' => self::STEP, 'status' => [LlmJobStatus::Queued, LlmJobStatus::Working]]) > 0) {
                throw new ConflictHttpException('The check is already running.');
            }
            $jobs->start(null, self::STEP, 'primary', static fn (int $id) => new CrossCheckProfile($id), $user);
        });

        return $this->redirectToRoute('author_cross_check_profile', status: Response::HTTP_SEE_OTHER);
    }
}
