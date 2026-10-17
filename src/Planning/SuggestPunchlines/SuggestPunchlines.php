<?php

namespace App\Planning\SuggestPunchlines;

use App\Shared\Llm\LlmJobMessage;

final readonly class SuggestPunchlines implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId, public int $round, public ?string $writerIdea = null)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
