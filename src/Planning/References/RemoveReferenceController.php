<?php

namespace App\Planning\References;

use App\Planning\Reference;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Removes a read (done or unreadable) reference while brainstorming, with its failed job cards, so no Retry points at it. */
final class RemoveReferenceController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/references/{referenceId<\d+>}/remove', name: 'planning_remove_reference', methods: ['POST'])]
    public function __invoke(Article $article, #[MapEntity(id: 'referenceId')] Reference $reference, EntityManagerInterface $em): Response
    {
        if ($reference->getArticle() !== $article) {
            throw new NotFoundHttpException();
        }
        if (ArticleState::Plan !== $article->getState()) {
            throw new ConflictHttpException('References can only be removed while brainstorming.');
        }
        if (ReferenceStatus::Pending === $reference->getStatus()) {
            throw new ConflictHttpException('The reference is still being read.');
        }

        $failedJobs = $em->getRepository(LlmJob::class)->findBy(['article' => $article, 'step' => ['extract_reference', 'digest_reference'], 'status' => LlmJobStatus::Failed]);
        $em->wrapInTransaction(static function () use ($em, $reference, $failedJobs): void {
            foreach ($failedJobs as $job) {
                $message = $job->storedMessage();
                if ($message instanceof ReferenceJob && $message->referenceId === $reference->getId()) {
                    $em->remove($job);
                }
            }
            $em->remove($reference);
        });

        return $this->redirectToRoute('planning_brainstorm', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
