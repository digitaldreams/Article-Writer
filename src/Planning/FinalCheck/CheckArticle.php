<?php

namespace App\Planning\FinalCheck;

use App\Shared\Llm\LlmJobMessage;

final readonly class CheckArticle implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
