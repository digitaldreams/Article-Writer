<?php

namespace App\Tests\Shared\Llm;

use App\Shared\Llm\LlmJobMessage;

final class TestLlmMessage implements LlmJobMessage
{
    public function __construct(private readonly int $jobId)
    {
    }

    public function llmJobId(): int
    {
        return $this->jobId;
    }
}
