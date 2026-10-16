<?php

namespace App\Planning\Brainstorm;

use App\Planning\PlanInput;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Autosave of audience, reader level and idea (fetch from Stimulus). */
final class SaveNotesController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/brainstorm/notes', name: 'planning_save_notes', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em): Response
    {
        if (ArticleState::Plan !== $article->getState()) {
            throw new ConflictHttpException('Notes can only change while brainstorming.');
        }

        $input = $em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.');
        $input->changeNotes(
            $request->request->getString('audience'),
            '' === $request->request->getString('readerLevel') ? null : $request->request->getString('readerLevel'),
            $request->request->getString('roughFlow'),
        );
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
