<?php

namespace App\Author\CrossCheckProfile;

/** Structured answer of CrossCheckProfile. */
final readonly class CrossCheckAnswer
{
    /**
     * @param list<CrossCheckPoint> $points at most 8, the most important first; empty when the profile breaks no rule
     */
    public function __construct(
        public array $points,
    ) {
    }
}
