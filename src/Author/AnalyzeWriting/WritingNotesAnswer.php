<?php

namespace App\Author\AnalyzeWriting;

/** Structured answer of ExtractWritingNotes: structure, then voice. */
final readonly class WritingNotesAnswer
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
        /** How the writer sounds, e.g. "friendly and direct", "dry humor". */
        public string $tone,
        /** Sentence length and person, e.g. "short sentences, speaks to the reader as you". */
        public string $sentenceStyle,
        /** Devices the writer uses, e.g. "rhetorical questions, analogies, bold key terms". */
        public string $devices,
        /** How code is shown and explained, e.g. "small snippet first, then a line-by-line walk". */
        public string $codeHabit,
        /** @var list<string> 2 to 3 short paragraphs copied word for word from the article that sound most like the writer */
        public array $samples,
    ) {
    }
}
