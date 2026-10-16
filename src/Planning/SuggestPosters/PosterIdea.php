<?php

namespace App\Planning\SuggestPosters;

use App\Shared\Article\Article;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class PosterIdea
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
        #[ORM\Column]
        private string $headline,
        #[ORM\Column(type: Types::TEXT)]
        private string $picture,
        #[ORM\Column(type: Types::TEXT)]
        private string $layout,
        #[ORM\Column]
        private string $colors,
        /** What the writer shared to make the poster better; null for the agent's own rounds. */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $writerIdea = null,
        /** A rough SVG of the idea; null when the agent sent none that is safe to show. */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $sketch = null,
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

    public function getHeadline(): string
    {
        return $this->headline;
    }

    public function getPicture(): string
    {
        return $this->picture;
    }

    public function getLayout(): string
    {
        return $this->layout;
    }

    public function getColors(): string
    {
        return $this->colors;
    }

    public function getWriterIdea(): ?string
    {
        return $this->writerIdea;
    }

    public function getSketch(): ?string
    {
        return $this->sketch;
    }
}
