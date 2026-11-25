<?php

namespace App\Planning\Outline;

final readonly class ProposedItem
{
    public function __construct(
        /** A short section title. */
        public string $title,
        /** One or two sentences about what goes in this section. */
        public string $summary,
    ) {
    }
}
