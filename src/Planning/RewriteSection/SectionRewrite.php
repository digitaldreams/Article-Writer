<?php

namespace App\Planning\RewriteSection;

use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A requested rewrite of one section; "after" is set by the job and waits for Accept or Reject. */
#[ORM\Entity]
class SectionRewrite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LlmJob $job = null;

    #[ORM\Column(name: 'after_text', type: Types::TEXT, nullable: true)]
    private ?string $after = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        #[ORM\Column]
        private string $heading,
        #[ORM\Column(type: Types::TEXT)]
        private string $instruction,
        /** The section text when the rewrite was asked; Accept writes only while the file still has it. */
        #[ORM\Column(name: 'before_text', type: Types::TEXT)]
        private string $before,
    ) {
    }

    public function trackedBy(LlmJob $job): void
    {
        $this->job = $job;
    }

    public function suggest(string $after): void
    {
        $this->after = $after;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticle(): Article
    {
        return $this->article;
    }

    public function getHeading(): string
    {
        return $this->heading;
    }

    public function getInstruction(): string
    {
        return $this->instruction;
    }

    public function getBefore(): string
    {
        return $this->before;
    }

    public function getAfter(): ?string
    {
        return $this->after;
    }

    public function getJob(): ?LlmJob
    {
        return $this->job;
    }
}
