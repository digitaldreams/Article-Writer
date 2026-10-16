<?php

namespace App\Planning;

use App\Shared\Article\Article;
use Doctrine\ORM\EntityManagerInterface;

/** Outline queries shared by the outline slices. */
final readonly class OutlineItems
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** 0 when the article has no outline yet. */
    public function latestRound(Article $article): int
    {
        return (int) $this->em->createQuery('SELECT MAX(i.round) FROM '.OutlineItem::class.' i WHERE i.article = :article')
            ->setParameter('article', $article)
            ->getSingleScalarResult();
    }

    /** @return list<OutlineItem> */
    public function ofRound(Article $article, int $round): array
    {
        return $this->em->getRepository(OutlineItem::class)->findBy(['article' => $article, 'round' => $round], ['position' => 'ASC']);
    }

    /** @return list<OutlineItem> */
    public function latest(Article $article): array
    {
        return $this->ofRound($article, $this->latestRound($article));
    }
}
