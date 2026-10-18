<?php

namespace App\Author\AnalyzeWriting;

/** Structured answer of MergeWritingProfile. The same fields are edited on /author/profile. */
final readonly class WritingProfileAnswer
{
    public function __construct(
        /** The way the author usually opens an article. */
        public string $openingType,
        /** The way the author usually orders sections. */
        public string $orderingHabit,
        /** Usual number of sections, e.g. "5 to 7". */
        public string $sectionCount,
        /** Whether the author usually runs one example through the article. */
        public string $runningExample,
        /** The way the author usually closes an article. */
        public string $closing,
        /** The writer's usual tone. */
        public string $tone,
        /** The writer's usual sentence length and person. */
        public string $sentenceStyle,
        /** Devices the writer uses again and again. */
        public string $devices,
        /** How the writer usually shows and explains code. */
        public string $codeHabit,
        /** @var list<string> 2 to 3 of the given samples, word for word, that best show the voice */
        public array $samples,
    ) {
    }
}
