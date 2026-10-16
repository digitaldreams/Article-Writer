<?php

namespace App\Planning;

/** Fired by ApprovePlan after the md files are written, in the transaction that moves the plan to approved. */
final readonly class PlanApproved
{
    public function __construct(public int $articleId)
    {
    }
}
