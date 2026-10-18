<?php

namespace App\Author;

use App\Shared\Article\Article;
use App\Shared\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** How one library article is built (structure) and sounds (voice). */
#[ORM\Entity]
class WritingNote
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
        #[ORM\Column(type: Types::TEXT)]
        public readonly string $openingType,
        #[ORM\Column]
        public readonly int $sectionCount,
        #[ORM\Column(type: Types::TEXT)]
        public readonly string $orderingPattern,
        #[ORM\Column]
        public readonly bool $hasRunningExample,
        #[ORM\Column(type: Types::TEXT)]
        public readonly string $closing,
        #[ORM\Column(type: Types::TEXT)]
        public readonly string $tone,
        #[ORM\Column(type: Types::TEXT)]
        public readonly string $sentenceStyle,
        #[ORM\Column(type: Types::TEXT)]
        public readonly string $devices,
        #[ORM\Column(type: Types::TEXT)]
        public readonly string $codeHabit,
        /** @var list<string> */
        #[ORM\Column(type: Types::JSON)]
        public readonly array $samples,
    ) {
        $this->user = $article->getUser();
    }

    public function getArticle(): Article
    {
        return $this->article;
    }
}
