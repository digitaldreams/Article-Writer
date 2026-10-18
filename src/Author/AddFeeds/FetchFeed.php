<?php

namespace App\Author\AddFeeds;

use App\Shared\Llm\LlmJobMessage;

final readonly class FetchFeed implements LlmJobMessage
{
    public function __construct(private int $llmJobId, public int $feedId)
    {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
