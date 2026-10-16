<?php

namespace App\Planning\DigestReferences;

use App\Shared\Llm\LlmJobMessage;

final readonly class DescribeImage implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $referenceId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
