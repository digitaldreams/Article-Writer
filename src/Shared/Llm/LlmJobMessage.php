<?php

namespace App\Shared\Llm;

/** Every LLM message carries the id of its LlmJob, so worker events and Retry can find the job. */
interface LlmJobMessage
{
    public function llmJobId(): int;
}
