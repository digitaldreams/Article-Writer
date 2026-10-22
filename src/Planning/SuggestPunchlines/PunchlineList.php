<?php

namespace App\Planning\SuggestPunchlines;

/** Structured answer of SuggestPunchlines. */
final readonly class PunchlineList
{
    /**
     * @param list<string> $punchlines exactly 5 different punchlines of 1 or 2 sentences, none of them already seen by the writer
     * @param list<string> $topics     exactly 5 Medium topics the article really covers, broad to narrow, 1 to 3 words each
     */
    public function __construct(
        public array $punchlines,
        public array $topics = [],
    ) {
    }
}
