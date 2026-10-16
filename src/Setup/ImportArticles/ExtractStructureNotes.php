<?php

namespace App\Setup\ImportArticles;

use App\Shared\Llm\LlmJobMessage;

final readonly class ExtractStructureNotes implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $articleId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
