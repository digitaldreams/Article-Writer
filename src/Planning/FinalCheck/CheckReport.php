<?php

namespace App\Planning\FinalCheck;

/** Structured answer of CheckArticle. */
final readonly class CheckReport
{
    /**
     * @param list<CheckFinding> $notes at most 8, the ones that matter most before the article goes live
     */
    public function __construct(
        public array $notes,
    ) {
    }
}
