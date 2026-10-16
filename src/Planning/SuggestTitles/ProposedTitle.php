<?php

namespace App\Planning\SuggestTitles;

final readonly class ProposedTitle
{
    /**
     * @param string $title    the main title, at most 70 characters
     * @param string $subtitle one line below the title that supports it, at most 120 characters
     */
    public function __construct(
        public string $title,
        public string $subtitle,
    ) {
    }
}
