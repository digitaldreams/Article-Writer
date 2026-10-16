<?php

namespace App\Planning\CreatePlan;

use App\Shared\Llm\LlmJobMessage;

final readonly class GeneratePlanTitle implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
