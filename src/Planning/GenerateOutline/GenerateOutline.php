<?php

namespace App\Planning\GenerateOutline;

use App\Shared\Llm\LlmJobMessage;

final readonly class GenerateOutline implements LlmJobMessage
{
    public function __construct(
        private int $llmJobId,
        public int $articleId,
        public int $round,
        public ?string $instruction = null,
    ) {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
