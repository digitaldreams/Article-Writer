<?php

namespace App\Planning;

use App\Shared\Article\Article;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One note of the reader review on a written article, tied to a "## " section (or "General"). */
#[ORM\Entity]
class ReaderNote
{
    public const GENERAL = 'General';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

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
