<?php

namespace App\Planning\FinalCheck;

/** Structured answer of RefactorArticle. */
final readonly class RefactorAnswer
{
    /**
     * @param list<string> $changes one short line per change; a line starts with "Code:" when code changed
     */
    public function __construct(
        /** The whole new article body in markdown, starting with the unchanged "# " title and "## " subtitle lines. */
        public string $body,
        public array $changes,
    ) {
    }
}
