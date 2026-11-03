<?php

namespace App\Planning\Outline;

/** Structured answer of GenerateOutline. */
final readonly class OutlineAnswer
{
    /**
     * @param list<ProposedItem> $items the outline in reading order
     */
    public function __construct(
        public array $items,
    ) {
    }
}
