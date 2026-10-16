<?php

namespace App\Planning\RewriteSection;

use App\Shared\Llm\LlmJobMessage;

final readonly class RewriteSection implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $rewriteId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
