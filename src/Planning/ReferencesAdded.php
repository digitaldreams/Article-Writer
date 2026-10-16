<?php

namespace App\Planning;

/** Fired by Brainstorm in its transaction when the author adds references to a plan. */
final readonly class ReferencesAdded
{
    /** @param list<int> $referenceIds */
    public function __construct(public array $referenceIds)
    {
    }
}
