<?php

namespace App\Planning\ReviewArticle;

/** Structured answer of ReviewArticle. */
final readonly class ReaderReview
{
    /**
     * @param list<ReviewNote> $notes at most 8, the ones that matter most to the reader
     */
    public function __construct(
        public array $notes,
    ) {
    }
}
