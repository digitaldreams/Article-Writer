<?php

namespace App\Planning;

/** Fired by EditOutline in the transaction that moves the plan to finalize. */
final readonly class FinalizeRequested
{
    public function __construct(public int $articleId, public int $round)
    {
    }
}
