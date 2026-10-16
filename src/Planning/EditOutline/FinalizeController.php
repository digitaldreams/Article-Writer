<?php

namespace App\Planning\EditOutline;

use App\Planning\FinalizeRequested;
use App\Planning\OutlineItems;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class FinalizeController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/outline/finalize', name: 'planning_finalize_outline', methods: ['POST'])]
    public function __invoke(Article $article, EntityManagerInterface $em, EventDispatcherInterface $events, OutlineItems $outline, OutlineGuard $guard): Response
    {
        $guard->assertEditable($article);
        if ([] === $outline->latest($article)) {
            throw new ConflictHttpException('An empty outline can\'t be finalized.');
        }

        $em->wrapInTransaction(static function () use ($article, $events, $outline): void {
            $article->moveTo(ArticleState::Finalize);
            $events->dispatch(new FinalizeRequested((int) $article->getId(), $outline->latestRound($article)));
        });

        return $this->redirectToRoute('planning_open_plan', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
