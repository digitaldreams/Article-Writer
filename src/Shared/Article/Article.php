<?php

namespace App\Shared\Article;

use App\Shared\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: ArticleState::class, length: 20)]
    private ArticleState $state;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column]
        private string $slug,
        #[ORM\Column]
        private string $title,
        ArticleState $state,
    ) {
        $this->state = $state;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public static function plan(User $user, string $slug, string $title): self
    {
        return new self($user, $slug, $title, ArticleState::Plan);
    }

    public static function imported(User $user, string $slug, string $title): self
    {
        return new self($user, $slug, $title, ArticleState::Imported);
    }

    public function moveTo(ArticleState $to): void
    {
        if (!\in_array($to, $this->state->allowedNext(), true)) {
            throw new InvalidArticleTransition($this->state, $to);
        }

        $this->state = $to;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** The article folder under ARTICLES_DIR is named by the id, so it exists once the article is flushed. */
    public function getFolder(): string
    {
        return (string) ($this->id ?? throw new \LogicException('The article has no id yet.'));
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getState(): ArticleState
    {
        return $this->state;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
