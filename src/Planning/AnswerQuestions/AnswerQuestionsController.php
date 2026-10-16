<?php

namespace App\Planning\AnswerQuestions;

use App\Planning\Question;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AnswerQuestionsController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/questions', name: 'planning_answer_questions', methods: ['GET'])]
    public function __invoke(Article $article, EntityManagerInterface $em): Response
    {
        return $this->render('planning/answer_questions/index.html.twig', [
            'article' => $article,
            'ask_job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'ask_questions'], ['id' => 'DESC']),
            'questions' => $em->getRepository(Question::class)->findBy(['article' => $article], ['position' => 'ASC']),
        ]);
    }
}
