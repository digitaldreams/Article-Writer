<?php

namespace App\Planning\FinalCheck;

use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The whole article refactored from the picked check notes; "after" is set by the job and waits for Accept or Reject. */
#[ORM\Entity]
class ArticleRefactor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LlmJob $job = null;

    #[ORM\Column(name: 'after_text', type: Types::TEXT, nullable: true)]
    private ?string $after = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changes = null;

    /**
     * @param list<int> $noteIds the check notes the refactor works from; Accept removes them
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        /** The article body when the refactor was asked; Accept writes only while the file still has it. */
        #[ORM\Column(name: 'before_text', type: Types::TEXT)]
        private string $before,
        #[ORM\Column(type: Types::JSON)]
        private array $noteIds,
    ) {
        $this->user = $article->getUser();
    }

    public function trackedBy(LlmJob $job): void
    {
        $this->job = $job;
    }

    /** @param list<string> $changes */
    public function suggest(string $after, array $changes): void
    {
        $this->after = $after;
        $this->changes = $changes;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticle(): Article
    {
        return $this->article;
    }

    public function getBefore(): string
    {
        return $this->before;
    }

    /** @return list<int> */
    public function getNoteIds(): array
    {
        return $this->noteIds;
    }

    public function getAfter(): ?string
    {
        return $this->after;
    }

    /** @return list<string> */
    public function getChanges(): array
    {
        return $this->changes ?? [];
    }

    public function getJob(): ?LlmJob
    {
        return $this->job;
    }
}
