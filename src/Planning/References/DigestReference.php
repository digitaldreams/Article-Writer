<?php

namespace App\Planning\References;

use App\Shared\Llm\LlmJobMessage;

final readonly class DigestReference implements LlmJobMessage, ReferenceJob
{
    public function __construct(private int $llmJobId, public int $referenceId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
