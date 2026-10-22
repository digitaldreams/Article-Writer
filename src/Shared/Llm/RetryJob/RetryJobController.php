<?php

namespace App\Shared\Llm\RetryJob;

use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RetryJobController extends AbstractController
{
    #[Route('/jobs/{id<\d+>}/retry', name: 'llm_retry_job', methods: ['POST'])]
    public function __invoke(int $id, EntityManagerInterface $em, MessageBusInterface $bus): Response
    {
        $job = $em->find(LlmJob::class, $id) ?? throw new NotFoundHttpException();
        // A queued job's message is still in the queue (dispatched in the job's transaction); a second copy would run the step twice.
        if (LlmJobStatus::Failed !== $job->getStatus()) {
            throw new ConflictHttpException('Only a failed job can be retried.');
        }

        $em->wrapInTransaction(function () use ($job, $bus): void {
            $job->requeue();
            $bus->dispatch($job->storedMessage());
        });

        return $this->redirectToRoute('llm_job_card', ['id' => $id], Response::HTTP_SEE_OTHER);
    }
}
