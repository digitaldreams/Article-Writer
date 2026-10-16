<?php

namespace App\Setup\ImportArticles;

/** Structured answer of ExtractStructureNotes. */
final readonly class StructureNotesAnswer
{
    public function __construct(
        /** How the article opens, e.g. "a real problem story", "a question", "a definition". */
        public string $openingType,
        public int $sectionCount,
        /** How the sections are ordered, e.g. "simple to advanced", "by feature", "step by step". */
        public string $orderingPattern,
        /** Does one example run through the whole article? */
        public bool $hasRunningExample,
        /** How the article ends, e.g. "summary", "next steps", "call to action". */
        public string $closing,
    ) {
    }
}
