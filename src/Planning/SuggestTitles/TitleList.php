<?php

namespace App\Planning\SuggestTitles;

/** Structured answer of SuggestTitles. */
final readonly class TitleList
{
    /**
     * @param list<ProposedTitle> $titles exactly 5 different title + subtitle pairs, none of them already seen by the writer
     */
    public function __construct(
        public array $titles,
    ) {
    }
}
