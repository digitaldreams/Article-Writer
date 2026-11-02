<?php

namespace App\Author\AnalyzeWriting;

/** Structured answer of MergeWritingProfile. The same fields are edited on /author/profile. */
final readonly class WritingProfileAnswer
{
    public function __construct(
        /** An instruction for the opening, with the writer's words in quotes. */
        public string $openingType,
        /** An instruction for the order of the sections. */
        public string $orderingHabit,
        /** Usual number of sections in the newest articles, e.g. '7 to 9'. */
        public string $sectionCount,
        /** An instruction for the running example. */
        public string $runningExample,
        /** An instruction for the last section. */
        public string $closing,
        /** An instruction for how to talk to the reader. */
        public string $tone,
        /** An instruction for sentence and paragraph length, and person. */
        public string $sentenceStyle,
        /** The writer's repeated phrases and tricks to use, quoted word for word. */
        public string $devices,
        /** An instruction for how to show and explain code. */
        public string $codeHabit,
        /** @var list<string> 2 to 3 of the given samples from the newest articles, word for word, that best show the voice */
        public array $samples,
    ) {
    }
}
