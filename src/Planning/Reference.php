<?php

namespace App\Planning;

use App\Shared\Article\Article;
use App\Shared\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Reference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(enumType: ReferenceStatus::class, length: 20)]
    private ReferenceStatus $status = ReferenceStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $extractedText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $digest = null;

    /** @param string $location the URL for a link, the `refs/<name>` path inside the article folder otherwise */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        #[ORM\Column(enumType: ReferenceType::class, length: 20)]
        private ReferenceType $type,
        #[ORM\Column(type: Types::TEXT)]
        private string $location,
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

    public function getType(): ReferenceType
    {
        return $this->type;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getStatus(): ReferenceStatus
    {
        return $this->status;
    }

    public function getExtractedText(): ?string
    {
        return $this->extractedText;
    }

    public function getDigest(): ?string
    {
        return $this->digest;
    }

    public function saveExtractedText(string $text): void
    {
        $this->extractedText = $text;
    }

    public function markDone(string $digest): void
    {
        $this->digest = $digest;
        $this->status = ReferenceStatus::Done;
    }

    public function markUnreadable(): void
    {
        $this->status = ReferenceStatus::Unreadable;
    }
}
