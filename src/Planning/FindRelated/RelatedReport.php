<?php

namespace App\Planning\FindRelated;

/** Structured answer of FindRelated. */
final readonly class RelatedReport
{
    /**
     * @param list<RelatedLink> $links at most 3 older articles worth linking; empty when none fits
     */
    public function __construct(
        public array $links,
    ) {
    }
}
