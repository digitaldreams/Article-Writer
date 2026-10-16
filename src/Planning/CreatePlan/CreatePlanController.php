<?php

namespace App\Planning\CreatePlan;

use App\Planning\PlanCreated;
use App\Planning\PlanInput;
use App\Planning\Reference;
use App\Planning\ReferencesSettled;
use App\Planning\ReferenceType;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CreatePlanController extends AbstractController
{
    #[Route('/plans/new', name: 'planning_create_plan', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        EntityManagerInterface $em,
        ArticleFolder $articleFolder,
        EventDispatcherInterface $events,
        LlmJobs $jobs,
    ): Response {
        $form = $this->createForm(CreatePlanType::class)->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('planning/create_plan/index.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
        }

        /** @var array{topic: string, audience: ?string, readerLevel: ?string, roughFlow: ?string, links: ?string, docs: list<UploadedFile>, images: list<UploadedFile>} $data */
        $data = $form->getData();

        $article = $em->wrapInTransaction(function () use ($data, $em, $articleFolder, $events, $jobs): Article {
            $article = Article::plan(ArticleFolder::slug($data['topic']), $data['topic']);
            $em->persist($article);
            $em->persist(new PlanInput($article, $data['topic'], $data['audience'] ?? '', $data['readerLevel'], $data['roughFlow'] ?? ''));
            $em->flush();
            $folder = $article->getFolder();
            $articleFolder->create($folder);

            $references = [];
            foreach (UrlLines::split($data['links']) as $url) {
                $references[] = new Reference($article, ReferenceType::Link, $url);
            }
            foreach ([[ReferenceType::Doc, $data['docs']], [ReferenceType::Image, $data['images']]] as [$type, $files]) {
                foreach ($files as $file) {
                    $references[] = new Reference($article, $type, $articleFolder->storeUpload($folder, $file));
                }
            }
            foreach ($references as $reference) {
                $em->persist($reference);
            }
            $em->flush();
            $jobs->start($article, 'generate_plan_title', 'secondary', static fn (int $id) => new GeneratePlanTitle($id, (int) $article->getId()));

            $events->dispatch([] === $references ? new ReferencesSettled((int) $article->getId()) : new PlanCreated((int) $article->getId()));

            return $article;
        });

        return $this->redirect('/plans/'.$article->getId());
    }
}
