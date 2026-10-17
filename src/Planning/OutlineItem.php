<?php

namespace App\Planning;

use App\Shared\Article\Article;
use App\Shared\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['article_id', 'round'])]
class OutlineItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private bool $editedByHand = false;

    #[ORM\Column(nullable: true)]
    private ?string $suggestedTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $suggestedSummary = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        #[ORM\Column]
        private int $round,
        #[ORM\Column]
        private int $position,
        #[ORM\Column]
        private string $title,
        #[ORM\Column(type: Types::TEXT)]
        private string $summary,
    ) {
        $this->user = $article->getUser();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticle(): Article
    {
        return $this->article;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function isEditedByHand(): bool
    {
        return $this->editedByHand;
    }

    public function getSuggestedTitle(): ?string
    {
        return $this->suggestedTitle;
    }

    public function getSuggestedSummary(): ?string
    {
        return $this->suggestedSummary;
    }

    public function editByHand(string $title, string $summary): void
    {
        $this->title = $title;
        $this->summary = $summary;
        $this->editedByHand = true;
    }

    /** A single-item rewrite by the model; not a hand edit. */
    public function rewrite(string $title, string $summary): void
    {
        $this->title = $title;
        $this->summary = $summary;
    }

    public function suggest(string $title, string $summary): void
    {
        $this->suggestedTitle = $title;
        $this->suggestedSummary = $summary;
    }

    public function acceptSuggestion(): void
    {
        if (null !== $this->suggestedTitle && null !== $this->suggestedSummary) {
            $this->title = $this->suggestedTitle;
            $this->summary = $this->suggestedSummary;
        }
        $this->clearSuggestion();
    }

    public function clearSuggestion(): void
    {
        $this->suggestedTitle = null;
        $this->suggestedSummary = null;
    }
}
