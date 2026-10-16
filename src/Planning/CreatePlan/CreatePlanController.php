<?php

namespace App\Planning\CreatePlan;

use App\Planning\PlanInput;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Saves the plan in state `plan`; the author brainstorms (audience, notes, references) on the next page. */
final class CreatePlanController extends AbstractController
{
    #[Route('/plans/new', name: 'planning_create_plan', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, ArticleFolder $articleFolder, LlmJobs $jobs): Response
    {
        $form = $this->createForm(CreatePlanType::class)->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('planning/create_plan/index.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
        }

        /** @var array{topic: string, idea: ?string} $data */
        $data = $form->getData();

        $article = $em->wrapInTransaction(function () use ($data, $em, $articleFolder, $jobs): Article {
            $article = Article::plan(ArticleFolder::slug($data['topic']), $data['topic']);
            $em->persist($article);
            $em->persist(new PlanInput($article, $data['topic'], '', null, $data['idea'] ?? ''));
            $em->flush();
            $articleFolder->create($article->getFolder());
            $jobs->start($article, 'generate_plan_title', 'secondary', static fn (int $id) => new GeneratePlanTitle($id, (int) $article->getId()));

            return $article;
        });

        return $this->redirectToRoute('planning_brainstorm', ['id' => $article->getId()]);
    }
}
