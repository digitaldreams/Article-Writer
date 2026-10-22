<?php

namespace App\Author\AnalyzeWriting;

use App\Shared\Llm\LlmJobMessage;

final readonly class MergeWritingProfile implements LlmJobMessage
{
    public function __construct(private int $llmJobId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
