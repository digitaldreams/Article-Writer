<?php

namespace App\Planning\CreatePlan;

use App\Planning\PlanInput;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Saves the plan in state `plan` from the topic (the modal on the Plans page); the author brainstorms (idea, audience, references) on the next page. */
final class CreatePlanController extends AbstractController
{
    #[Route('/plans/new', name: 'planning_create_plan', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, ArticleFolder $articleFolder, LlmJobs $jobs, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(CreatePlanType::class)->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('planning/create_plan/index.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
        }

        /** @var array{topic: string} $data */
        $data = $form->getData();

        $article = $em->wrapInTransaction(function () use ($data, $em, $articleFolder, $jobs, $user): Article {
            $article = Article::plan($user, ArticleFolder::slug($data['topic']), $data['topic']);
            $em->persist($article);
            $em->persist(new PlanInput($article, $data['topic'], '', null, ''));
            $em->flush();
            $articleFolder->create($article->getFolder());
            $jobs->start($article, 'generate_plan_title', 'secondary', static fn (int $id) => new GeneratePlanTitle($id, (int) $article->getId()));

            return $article;
        });

        return $this->redirectToRoute('planning_brainstorm', ['id' => $article->getId()]);
    }
}
