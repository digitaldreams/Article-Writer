<?php

namespace App\Planning\EditOutline;

use App\Planning\OutlineItem;
use App\Shared\Article\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Autosave of one item's title and summary, or its removal. */
final class EditItemController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/outline/items/{itemId<\d+>}', name: 'planning_edit_outline_item', methods: ['POST'])]
    public function __invoke(
        Article $article,
        #[MapEntity(id: 'itemId')] OutlineItem $item,
        Request $request,
        EntityManagerInterface $em,
        OutlineGuard $guard,
    ): Response {
        $guard->assertEditable($article, $item);

        if ($request->request->has('remove')) {
            $em->remove($item);
            $em->flush();

            return $this->redirectToRoute('planning_show_outline', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
        }

        $title = trim($request->request->getString('title'));
        if ('' === $title) {
            return new Response('The title is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $item->editByHand($title, trim($request->request->getString('summary')));
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
