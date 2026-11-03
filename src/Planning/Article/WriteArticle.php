<?php

namespace App\Planning\Article;

use App\Shared\Llm\LlmJobMessage;

final readonly class WriteArticle implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
