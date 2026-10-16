<?php

namespace App\Planning;

/** Asks for a new outline round: the first one after a flow is chosen, a direction change, or a reject. */
final readonly class OutlineRoundRequested
{
    public function __construct(
        public int $articleId,
        public int $round,
        public ?string $instruction = null,
        public ?string $reason = null,
    ) {
    }
}
