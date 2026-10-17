<?php

namespace App\Planning\SuggestPunchlines;

use App\Shared\Article\Article;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Punchline
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
        private int $round,
        #[ORM\Column(type: Types::TEXT)]
        private string $text,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $writerIdea = null,
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

    public function getRound(): int
    {
        return $this->round;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getWriterIdea(): ?string
    {
        return $this->writerIdea;
    }
}
