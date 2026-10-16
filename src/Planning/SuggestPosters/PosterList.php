<?php

namespace App\Planning\SuggestPosters;

/** Structured answer of SuggestPosters. */
final readonly class PosterList
{
    /**
     * @param list<ProposedPoster> $posters exactly 3 different poster ideas, none of them already seen by the writer
     */
    public function __construct(
        public array $posters,
    ) {
    }
}
