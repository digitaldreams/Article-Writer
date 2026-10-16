<?php

namespace App\Setup\ImportArticles;

/** Structured answer of MergeStructureProfile. The same five fields are edited on /setup/profile. */
final readonly class StructureProfileAnswer
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
    ) {
    }
}
