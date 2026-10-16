<?php

namespace App\Planning;

/** Fired by Brainstorm in its transaction when the author clicks Next with every reference settled. */
final readonly class BrainstormDone
{
    public function __construct(public int $articleId)
    {
    }
}
