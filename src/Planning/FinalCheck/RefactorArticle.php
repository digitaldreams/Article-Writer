<?php

namespace App\Planning\FinalCheck;

use App\Shared\Llm\LlmJobMessage;

final readonly class RefactorArticle implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $refactorId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
