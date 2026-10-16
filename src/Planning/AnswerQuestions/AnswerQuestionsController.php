<?php

namespace App\Planning\AnswerQuestions;

use App\Planning\Question;
use App\Planning\Reference;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AnswerQuestionsController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/questions', name: 'planning_answer_questions', methods: ['GET'])]
    public function __invoke(Article $article, EntityManagerInterface $em): Response
    {
        $references = $em->getRepository(Reference::class)->findBy(['article' => $article], ['id' => 'ASC']);

        return $this->render('planning/answer_questions/index.html.twig', [
            'article' => $article,
            'references' => $references,
            'digesting' => [] !== array_filter($references, static fn (Reference $r) => ReferenceStatus::Pending === $r->getStatus()),
            'ask_job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'ask_questions'], ['id' => 'DESC']),
            'questions' => $em->getRepository(Question::class)->findBy(['article' => $article], ['position' => 'ASC']),
            'failed_jobs' => $em->getRepository(LlmJob::class)->findBy(
                ['article' => $article, 'step' => ['extract_reference', 'describe_image', 'digest_reference'], 'status' => LlmJobStatus::Failed],
                ['id' => 'ASC'],
            ),
        ]);
    }
}
