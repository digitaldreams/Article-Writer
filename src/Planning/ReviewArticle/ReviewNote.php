<?php

namespace App\Planning\ReviewArticle;

final readonly class ReviewNote
{
    public function __construct(
        /** The exact "## " heading of the section the note is about, or "General" for the whole article. */
        public string $section,
        /** Where the reader gets lost, bored or needs more, in the reader's own words. */
        public string $problem,
        /** One concrete change to the section that fixes it. */
        public string $suggestion,
    ) {
    }
}
