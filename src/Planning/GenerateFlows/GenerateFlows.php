<?php

namespace App\Planning\GenerateFlows;

use App\Shared\Llm\LlmJobMessage;

final readonly class GenerateFlows implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
