<?php

namespace App\Planning\Brainstorm;

use App\Planning\PlanInput;
use App\Planning\Reference;
use App\Planning\ReferencesAdded;
use App\Planning\ReferenceStatus;
use App\Planning\ReferenceType;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** The Brainstorming tab: notes, references and their status chips. POST adds references while the plan is in `plan`. */
final class BrainstormController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/brainstorm', name: 'planning_brainstorm', methods: ['GET', 'POST'])]
    public function __invoke(
        Article $article,
        Request $request,
        EntityManagerInterface $em,
        ArticleFolder $articleFolder,
        EventDispatcherInterface $events,
    ): Response {
        $form = $this->createForm(AddReferencesType::class, options: ['action' => $this->generateUrl('planning_brainstorm', ['id' => $article->getId()])])->handleRequest($request);

        if ($request->isMethod('POST') && ArticleState::Plan !== $article->getState()) {
            throw new ConflictHttpException('References can only be added while brainstorming.');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{links: ?string, docs: list<UploadedFile>} $data */
            $data = $form->getData();
            $em->wrapInTransaction(function () use ($article, $data, $em, $articleFolder, $events): void {
                $references = [];
                foreach (UrlLines::split($data['links']) as $url) {
                    $references[] = new Reference($article, ReferenceType::Link, $url);
                }
                foreach ($data['docs'] as $file) {
                    $references[] = new Reference($article, ReferenceType::Doc, $articleFolder->storeUpload($article->getFolder(), $file));
                }
                foreach ($references as $reference) {
                    $em->persist($reference);
                }
                $em->flush();
                $events->dispatch(new ReferencesAdded(array_map(static fn (Reference $r) => (int) $r->getId(), $references)));
            });

            return $this->redirectToRoute('planning_brainstorm', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
        }

        $references = $em->getRepository(Reference::class)->findBy(['article' => $article], ['id' => 'ASC']);
        $jobs = $em->getRepository(LlmJob::class);

        return $this->render('planning/brainstorm/index.html.twig', [
            'article' => $article,
            'input' => $em->getRepository(PlanInput::class)->findOneBy(['article' => $article]),
            'form' => $form,
            'references' => $references,
            'reading' => \count(array_filter($references, static fn (Reference $r) => ReferenceStatus::Pending === $r->getStatus())),
            'failed_jobs' => $jobs->findBy(
                ['article' => $article, 'step' => ['extract_reference', 'digest_reference'], 'status' => LlmJobStatus::Failed],
                ['id' => 'ASC'],
            ),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
