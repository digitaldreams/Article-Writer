<?php

namespace App\Author\AddFeeds;

use App\Shared\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** An RSS or Atom feed of the author's posts; Refresh imports its new items into the library. */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['user_id', 'url'])]
class Feed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRefreshedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: Types::TEXT)]
        private string $url,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getLastRefreshedAt(): ?\DateTimeImmutable
    {
        return $this->lastRefreshedAt;
    }

    public function refreshed(): void
    {
        $this->lastRefreshedAt = new \DateTimeImmutable();
    }
}
