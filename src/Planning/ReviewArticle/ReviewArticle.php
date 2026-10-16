<?php

namespace App\Planning\ReviewArticle;

use App\Shared\Llm\LlmJobMessage;

final readonly class ReviewArticle implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
