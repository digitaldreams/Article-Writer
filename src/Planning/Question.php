<?php

namespace App\Planning;

use App\Shared\Article\Article;
use App\Shared\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Question
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $answer = null;

    #[ORM\Column]
    private bool $skipped = false;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        #[ORM\Column(type: Types::TEXT)]
        private string $text,
        #[ORM\Column]
        private int $position,
        /** @var list<string> empty for an open question */
        #[ORM\Column(type: Types::JSON)]
        private array $options = [],
        /** With options: true lets the writer pick several (checkboxes), false exactly one (radio). */
        #[ORM\Column(options: ['default' => false])]
        private bool $multiple = false,
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

    public function getText(): string
    {
        return $this->text;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /** @return list<string> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /** Several picks are saved on one line, joined by "; ", so prompts and answers.md read them as text. */
    public function getAnswer(): ?string
    {
        return $this->answer;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function saveAnswer(string $answer): void
    {
        $this->answer = '' === trim($answer) ? null : $answer;
        $this->skipped = false;
    }

    public function skip(): void
    {
        $this->skipped = true;
    }
}
