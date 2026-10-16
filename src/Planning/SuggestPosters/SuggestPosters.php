<?php

namespace App\Planning\SuggestPosters;

use App\Shared\Llm\LlmJobMessage;

final readonly class SuggestPosters implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId, public int $round, public ?string $writerIdea = null)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
