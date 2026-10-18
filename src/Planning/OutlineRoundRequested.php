<?php

namespace App\Planning;

/** Asks for a new outline round: the first one after brainstorm or questions, or a direction change. */
final readonly class OutlineRoundRequested
{
    public function __construct(
        public int $articleId,
        public int $round,
        public ?string $instruction = null,
    ) {
    }
}
