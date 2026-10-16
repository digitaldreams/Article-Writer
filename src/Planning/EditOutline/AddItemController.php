<?php

namespace App\Planning\EditOutline;

use App\Planning\OutlineItem;
use App\Planning\OutlineItems;
use App\Shared\Article\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Adds an item at the end of the latest round. */
final class AddItemController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/outline/items', name: 'planning_add_outline_item', methods: ['POST'])]
    public function __invoke(Article $article, Request $request, EntityManagerInterface $em, OutlineItems $outline, OutlineGuard $guard): Response
    {
        $guard->assertEditable($article);
        $title = trim($request->request->getString('title'));
        if ('' === $title) {
            return new Response('The title is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $items = $outline->latest($article);
        $last = end($items);
        $item = new OutlineItem($article, max(1, $outline->latestRound($article)), false === $last ? 0 : $last->getPosition() + 1, $title, trim($request->request->getString('summary')));
        $item->editByHand($title, $item->getSummary());
        $em->persist($item);
        $em->flush();

        return $this->redirectToRoute('planning_show_outline', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
