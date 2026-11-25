<?php

namespace App\Planning\Brainstorm;

use App\Planning\AddReferencesType;
use App\Planning\PlanInput;
use App\Planning\Reference;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The Brainstorming tab: notes, references and their status chips. $referencesForm is the form References sends back with its errors. */
final class BrainstormController extends AbstractController
{
    /** @param FormInterface<array<string, mixed>>|null $referencesForm */
    #[Route('/plans/{id<\d+>}/brainstorm', name: 'planning_brainstorm', methods: ['GET'])]
    public function __invoke(Article $article, EntityManagerInterface $em, ?FormInterface $referencesForm = null): Response
    {
        $references = $em->getRepository(Reference::class)->findBy(['article' => $article], ['id' => 'ASC']);
        $jobs = $em->getRepository(LlmJob::class);

        return $this->render('planning/brainstorm/index.html.twig', [
            'article' => $article,
            'input' => $em->getRepository(PlanInput::class)->findOneBy(['article' => $article]),
            'form' => $referencesForm ?? $this->createForm(AddReferencesType::class, options: ['action' => $this->generateUrl('planning_add_references', ['id' => $article->getId()])]),
            'references' => $references,
            'reading' => \count(array_filter($references, static fn (Reference $r) => ReferenceStatus::Pending === $r->getStatus())),
            'reading_since' => $jobs->findOneBy(
                ['article' => $article, 'step' => ['extract_reference', 'digest_reference'], 'status' => [LlmJobStatus::Queued, LlmJobStatus::Working]],
                ['createdAt' => 'ASC'],
            )?->getCreatedAt(),
            'failed_jobs' => $jobs->findBy(
                ['article' => $article, 'step' => ['extract_reference', 'digest_reference'], 'status' => LlmJobStatus::Failed],
                ['id' => 'ASC'],
            ),
        ], new Response(status: null === $referencesForm ? 200 : 422));
    }
}
