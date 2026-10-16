<?php

namespace App\Planning\GenerateFlows;

/** Structured answer of GenerateFlows. */
final readonly class FlowList
{
    /**
     * @param list<string> $flows 2 or 3 flows. Each one names its perspective, then says in a few sentences how the article is written from it.
     */
    public function __construct(
        public array $flows,
    ) {
    }
}
