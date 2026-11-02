<?php

namespace App\Author\CrossCheckProfile;

use App\Shared\Llm\LlmJobMessage;

final readonly class CrossCheckProfile implements LlmJobMessage
{
    public function __construct(private int $llmJobId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
