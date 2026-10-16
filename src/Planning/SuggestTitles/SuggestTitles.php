<?php

namespace App\Planning\SuggestTitles;

use App\Shared\Llm\LlmJobMessage;

final readonly class SuggestTitles implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId, public int $round)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
