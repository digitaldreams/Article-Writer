<?php

namespace App\Planning\ReviewPolish;

use App\Planning\OutlineItem;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Accept copies the suggestion into the item; reject keeps the original. Either way the suggestion is gone. */
final class DecideSuggestionController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/review/items/{itemId<\d+>}', name: 'planning_decide_suggestion', methods: ['POST'])]
    public function __invoke(
        Article $article,
        #[MapEntity(id: 'itemId')] OutlineItem $item,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if ($item->getArticle() !== $article) {
            throw new NotFoundHttpException();
        }
        if (ArticleState::Finalize !== $article->getState()) {
            throw new ConflictHttpException('Suggestions can only be decided at the review step.');
        }

        'accept' === $request->request->getString('decision') ? $item->acceptSuggestion() : $item->clearSuggestion();
        $em->flush();

        return $this->redirectToRoute('planning_show_review', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
