<?php

namespace App\Setup\ImportArticles;

use App\Shared\Article\Article;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class StructureNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        #[ORM\Column]
        public readonly string $openingType,
        #[ORM\Column]
        public readonly int $sectionCount,
        #[ORM\Column]
        public readonly string $orderingPattern,
        #[ORM\Column]
        public readonly bool $hasRunningExample,
        #[ORM\Column]
        public readonly string $closing,
    ) {
    }

    public function getArticle(): Article
    {
        return $this->article;
    }
}
