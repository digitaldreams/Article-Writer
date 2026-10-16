<?php

namespace App\Planning\AskQuestions;

use App\Shared\Llm\LlmJobMessage;

final readonly class AskQuestions implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
