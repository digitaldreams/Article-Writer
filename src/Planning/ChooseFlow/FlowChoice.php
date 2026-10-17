<?php

namespace App\Planning\ChooseFlow;

use App\Planning\FlowOption;
use App\Planning\OutlineItems;
use App\Planning\OutlineRoundRequested;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/** Choosing a flow starts the next outline round: round 1, or n+1 after a reject back to flows. */
final readonly class FlowChoice
{
    public function __construct(private EntityManagerInterface $em, private EventDispatcherInterface $events, private OutlineItems $outline)
    {
    }

    public function choose(FlowOption $flow): void
    {
        $article = $flow->getArticle();
        $this->em->wrapInTransaction(function () use ($article, $flow): void {
            foreach ($this->em->getRepository(FlowOption::class)->findBy(['article' => $article]) as $option) {
                $option->unchoose();
            }
            $this->em->persist($flow);
            $flow->choose();
            $article->moveTo(ArticleState::Outline);
            $this->em->flush();
            $this->events->dispatch(new OutlineRoundRequested((int) $article->getId(), $this->outline->latestRound($article) + 1));
        });
    }
}
