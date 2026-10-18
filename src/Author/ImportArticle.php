<?php

namespace App\Author;

use App\Shared\Llm\LlmJobMessage;

/** One article for the library. The content travels in the message: the uploaded temp file is gone when the worker runs. */
final readonly class ImportArticle implements LlmJobMessage
{
    public function __construct(
        private int $llmJobId,
        public string $filename,
        public string $content,
        public ?string $sourceUrl = null,
        public ?\DateTimeImmutable $publishedAt = null,
        /** @var 'md'|'medium'|'html' html: a feed item, its title in filename */
        public string $format = 'md',
    ) {
    }

    public function llmJobId(): int
    {
        return $this->llmJobId;
    }
}
