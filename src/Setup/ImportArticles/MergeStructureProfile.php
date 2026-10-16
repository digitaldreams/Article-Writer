<?php

namespace App\Setup\ImportArticles;

use App\Shared\Llm\LlmJobMessage;

final readonly class MergeStructureProfile implements LlmJobMessage
{
    public function __construct(private int $llmJobId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
