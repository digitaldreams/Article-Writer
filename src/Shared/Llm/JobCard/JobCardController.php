<?php

namespace App\Shared\Llm\JobCard;

use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class JobCardController extends AbstractController
{
    #[Route('/jobs/{id<\d+>}/card', name: 'llm_job_card', methods: ['GET'])]
    public function __invoke(int $id, EntityManagerInterface $em): Response
    {
        $job = $em->find(LlmJob::class, $id) ?? throw new NotFoundHttpException();

        return $this->render('shared/llm/job_card/_frame.html.twig', ['job' => $job, 'with_src' => false]);
    }
}
