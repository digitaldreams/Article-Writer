<?php

namespace App\Author\AnalyzeWriting;

/** Structured answer of ExtractWritingNotes: structure, then voice. */
final readonly class WritingNotesAnswer
{
    public function __construct(
        /** The first paragraphs, with the writer's words in quotes, e.g. a pain question 'Remember the last time you…?', then a one-line promise. */
        public string $openingType,
        /** Number of main sections (top-level headings). */
        public int $sectionCount,
        /** How one section leads to the next, e.g. 'one coding journey: each section adds the next feature to the same code'. */
        public string $orderingPattern,
        /** Does one example run through the whole article? */
        public bool $hasRunningExample,
        /** The last section only, e.g. 'X replaced Y' bullets, then a short closing line that echoes the hook. */
        public string $closing,
        /** How the writer talks to the reader, e.g. 'warm and direct; 'we' for the journey, 'you' for the reader's project'. */
        public string $tone,
        /** Sentence and paragraph length, and person, e.g. 'short sentences, 2 to 4 sentences a paragraph'. */
        public string $sentenceStyle,
        /** The writer's repeated phrases and tricks, quoted word for word, e.g. 'That's it!', ❌ / ✅ code pairs, 'Note: … Good question!'. */
        public string $devices,
        /** How code is shown and explained, e.g. 'core code only, then 'A few things to notice:' bullets'. */
        public string $codeHabit,
        /** @var list<string> 2 to 3 whole paragraphs copied word for word that sound most like the writer; none ends with ':' */
        public array $samples,
    ) {
    }
}
