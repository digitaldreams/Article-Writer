<?php

namespace App\Planning\References;

use App\Planning\AddReferencesType;
use App\Planning\Reference;
use App\Planning\UrlLines;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Saves the links typed on the Brainstorming tab while the plan is in `plan`, with one extract job per reference. */
final class AddReferencesController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/references', name: 'planning_add_references', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, LlmJobs $jobs): Response
    {
        if (ArticleState::Plan !== $article->getState()) {
            throw new ConflictHttpException('References can only be added while brainstorming.');
        }

        $form = $this->createForm(AddReferencesType::class, options: ['action' => $this->generateUrl('planning_add_references', ['id' => $article->getId()])])->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            // The Brainstorming page belongs to Brainstorm; it renders the form errors. _route keeps its tab current.
            return $this->forward('App\Planning\Brainstorm\BrainstormController', ['article' => $article, 'referencesForm' => $form, '_route' => 'planning_brainstorm']);
        }

        /** @var array{links: ?string} $data */
        $data = $form->getData();
        $em->wrapInTransaction(static function () use ($article, $data, $em, $jobs): void {
            $references = array_map(static fn (string $url) => new Reference($article, $url), UrlLines::split($data['links']));
            foreach ($references as $reference) {
                $em->persist($reference);
            }
            $em->flush();
            foreach ($references as $reference) {
                $referenceId = (int) $reference->getId();
                $jobs->start($article, 'extract_reference', 'io', static fn (int $id) => new ExtractReference($id, $referenceId));
            }
        });

        return $this->redirectToRoute('planning_brainstorm', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
