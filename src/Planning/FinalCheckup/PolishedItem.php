<?php

namespace App\Planning\FinalCheckup;

final readonly class PolishedItem
{
    public function __construct(
        public string $title,
        public string $summary,
    ) {
    }
}
