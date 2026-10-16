<?php

namespace App\Planning;

/** Fired by CreatePlan in its transaction when the new plan has references to digest. */
final readonly class PlanCreated
{
    public function __construct(public int $articleId)
    {
    }
}
