<?php

namespace App\Planning\SuggestPunchlines;

/** Structured answer of SuggestPunchlines. */
final readonly class PunchlineList
{
    /**
     * @param list<string> $punchlines exactly 5 different punchlines of 1 or 2 sentences, none of them already seen by the writer
     */
    public function __construct(
        public array $punchlines,
    ) {
    }
}
