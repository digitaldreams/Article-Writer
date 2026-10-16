<?php

namespace App\Planning\SuggestPosters;

use App\Shared\Llm\LlmJobMessage;

final readonly class GeneratePoster implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $ideaId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
