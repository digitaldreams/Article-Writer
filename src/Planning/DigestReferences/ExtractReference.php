<?php

namespace App\Planning\DigestReferences;

use App\Planning\ReferenceJob;
use App\Shared\Llm\LlmJobMessage;

final readonly class ExtractReference implements LlmJobMessage, ReferenceJob
{
    public function __construct(private int $llmJobId, public int $referenceId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
