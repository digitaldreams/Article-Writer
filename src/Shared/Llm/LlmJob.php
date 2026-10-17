<?php

namespace App\Shared\Llm;

use App\Shared\Article\Article;
use App\Shared\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class LlmJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(enumType: LlmJobStatus::class, length: 20)]
    private LlmJobStatus $status = LlmJobStatus::Queued;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /** The serialized message (base64: PDO pgsql cuts text at NUL bytes), so Retry can dispatch the same one again. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $outputTokens = null;

    /** USD; null when the model has no price or nothing was counted. */
    #[ORM\Column(nullable: true)]
    private ?float $cost = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Article $article,
        #[ORM\Column(length: 50)]
        private string $step,
        #[ORM\Column(length: 20)]
        private string $role,
        #[ORM\Column(length: 50)]
        private string $provider,
        #[ORM\Column]
        private string $model,
        ?User $user = null,
    ) {
        $this->user = $article?->getUser() ?? $user ?? throw new \LogicException('A job without an article needs its user.');
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Called once the job has an id and the message that carries it exists. */
    public function storeMessage(LlmJobMessage $message): void
    {
        $this->message = base64_encode(serialize($message));
    }

    public function storedMessage(): LlmJobMessage
    {
        $message = null === $this->message ? false : unserialize(base64_decode($this->message));
        if (!$message instanceof LlmJobMessage) {
            throw new \LogicException('The job has no stored message.');
        }

        return $message;
    }

    public function markWorking(): void
    {
        $this->status = LlmJobStatus::Working;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function markDone(): void
    {
        $this->status = LlmJobStatus::Done;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $error): void
    {
        $this->status = LlmJobStatus::Failed;
        $this->error = $error;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /** Adds one attempt's usage; a retried job keeps what its failed attempts cost. */
    public function recordUsage(int $inputTokens, int $outputTokens, ?float $cost): void
    {
        $this->inputTokens = ($this->inputTokens ?? 0) + $inputTokens;
        $this->outputTokens = ($this->outputTokens ?? 0) + $outputTokens;
        if (null !== $cost) {
            $this->cost = ($this->cost ?? 0.0) + $cost;
        }
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function getCost(): ?float
    {
        return $this->cost;
    }

    /** createdAt is the "Queued" time, so a retried job counts its wait from the retry. */
    public function requeue(): void
    {
        $this->status = LlmJobStatus::Queued;
        $this->error = null;
        $this->createdAt = new \DateTimeImmutable();
        $this->startedAt = $this->finishedAt = null;
    }

    /** Queued this long: the message was most likely lost, so Retry may send it again. */
    public function isStuck(\DateTimeInterface $now): bool
    {
        return LlmJobStatus::Queued === $this->status
            && $this->createdAt < \DateTimeImmutable::createFromInterface($now)->modify('-10 minutes');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getStatus(): LlmJobStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
