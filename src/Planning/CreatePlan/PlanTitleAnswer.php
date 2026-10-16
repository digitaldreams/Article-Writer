<?php

namespace App\Planning\CreatePlan;

/** Structured answer of GeneratePlanTitle. */
final readonly class PlanTitleAnswer
{
    public function __construct(
        /** A short, clear article title. */
        public string $title,
    ) {
    }
}
