<?php

namespace App\Planning;

use App\Shared\Article\Article;
use App\Shared\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class PlanInput
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        #[ORM\Column(type: Types::TEXT)]
        private string $topic,
        #[ORM\Column(type: Types::TEXT)]
        private string $audience,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $readerLevel,
        #[ORM\Column(type: Types::TEXT)]
        private string $roughFlow,
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

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getAudience(): string
    {
        return $this->audience;
    }

    public function getReaderLevel(): ?string
    {
        return $this->readerLevel;
    }

    public function getRoughFlow(): string
    {
        return $this->roughFlow;
    }

    public function changeNotes(string $audience, ?string $readerLevel, string $roughFlow): void
    {
        $this->audience = $audience;
        $this->readerLevel = $readerLevel;
        $this->roughFlow = $roughFlow;
    }
}
