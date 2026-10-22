<?php

namespace App\Planning\FinalCheck;

use App\Shared\Article\Article;
use App\Shared\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One note of the final check on a written article, tied to a "## " section (or "General"). */
#[ORM\Entity]
class CheckNote
{
    public const GENERAL = 'General';

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
        private string $section,
        #[ORM\Column(type: Types::TEXT)]
        private string $problem,
        #[ORM\Column(type: Types::TEXT)]
        private string $suggestion,
        #[ORM\Column]
        private int $position,
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

    public function getSection(): string
    {
        return $this->section;
    }

    public function getProblem(): string
    {
        return $this->problem;
    }

    public function getSuggestion(): string
    {
        return $this->suggestion;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
