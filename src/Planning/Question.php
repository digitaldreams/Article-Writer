<?php

namespace App\Planning;

use App\Shared\Article\Article;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Question
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $answer = null;

    #[ORM\Column]
    private bool $skipped = false;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        #[ORM\Column(enumType: QuestionRole::class, length: 20)]
        private QuestionRole $role,
        #[ORM\Column(type: Types::TEXT)]
        private string $text,
        #[ORM\Column]
        private int $position,
        /** @var list<string> empty for an open question */
        #[ORM\Column(type: Types::JSON)]
        private array $options = [],
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

    public function getRole(): QuestionRole
    {
        return $this->role;
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
