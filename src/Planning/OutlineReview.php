<?php

namespace App\Planning;

use App\Shared\Article\Article;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class OutlineReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        #[ORM\Column]
        private int $round,
        #[ORM\Column(enumType: OutlineDecision::class, length: 20)]
        private OutlineDecision $decision,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $reason = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function getDecision(): OutlineDecision
    {
        return $this->decision;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
