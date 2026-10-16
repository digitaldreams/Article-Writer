<?php

namespace App\Planning;

use App\Shared\Article\Article;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The issues the final checkup found in one outline round. Written by FinalCheckup, shown by ReviewPolish. */
#[ORM\Entity]
class OutlineCheckup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @param list<string> $issues */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        #[ORM\Column]
        private int $round,
        #[ORM\Column(type: Types::JSON)]
        private array $issues,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    /** @return list<string> */
    public function getIssues(): array
    {
        return $this->issues;
    }
}
