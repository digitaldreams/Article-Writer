<?php

namespace App\Planning\ChooseFlow;

use App\Planning\FlowOption;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowFlowsController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/flows', name: 'planning_show_flows', methods: ['GET'])]
    public function __invoke(Article $article, EntityManagerInterface $em): Response
    {
        return $this->render('planning/choose_flow/index.html.twig', [
            'article' => $article,
            'job' => $em->getRepository(LlmJob::class)->findOneBy(['article' => $article, 'step' => 'generate_flows'], ['id' => 'DESC']),
            'flows' => $em->getRepository(FlowOption::class)->findBy(['article' => $article], ['id' => 'ASC']),
        ]);
    }
}
