<?php

namespace App\Author\AnalyzeWriting;

use App\Shared\Llm\LlmJobMessage;

final readonly class ExtractWritingNotes implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
