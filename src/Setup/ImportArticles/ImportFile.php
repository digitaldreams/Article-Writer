<?php

namespace App\Setup\ImportArticles;

use App\Shared\Llm\LlmJobMessage;

/** The md content travels in the message: the uploaded temp file is gone when the worker runs. */
final readonly class ImportFile implements LlmJobMessage
{
    public function __construct(
        private int $llmJobId,
        public string $filename,
        public string $content,
        public \DateTimeImmutable $uploadedAt,
        public ?\DateTimeImmutable $fileDate = null,
    ) {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
