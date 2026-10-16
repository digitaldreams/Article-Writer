<?php

namespace App\Planning\EditOutline;

use App\Planning\OutlineItems;
use App\Planning\OutlineRoundRequested;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** A direction change asks for round n+1 with the writer's instruction. */
final class ChangeDirectionController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/outline/direction', name: 'planning_change_direction', methods: ['POST'])]
    public function __invoke(
        Article $article,
        Request $request,
        EntityManagerInterface $em,
        EventDispatcherInterface $events,
        OutlineItems $outline,
        OutlineGuard $guard,
    ): Response {
        $guard->assertEditable($article);
        $instruction = trim($request->request->getString('instruction'));
        if ('' === $instruction) {
            return $this->forward(ShowOutlineController::class, ['article' => $article, 'directionError' => 'Say what should change.']);
        }

        $em->wrapInTransaction(static function () use ($article, $instruction, $events, $outline): void {
            $article->moveTo(ArticleState::Outline);
            $events->dispatch(new OutlineRoundRequested((int) $article->getId(), $outline->latestRound($article) + 1, instruction: $instruction));
        });

        return $this->redirectToRoute('planning_show_outline', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
