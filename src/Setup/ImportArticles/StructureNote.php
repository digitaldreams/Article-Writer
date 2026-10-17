<?php

namespace App\Setup\ImportArticles;

use App\Shared\Article\Article;
use App\Shared\User\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class StructureNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

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
        $this->user = $article->getUser();
    }

    public function getArticle(): Article
    {
        return $this->article;
    }
}
