<?php

namespace App\Planning\FinalCheckup;

/** Structured answer of FinalCheckup. */
final readonly class CheckupAnswer
{
    /**
     * @param list<string>       $issues problems found against the plan, the answers and the structure profile, e.g. "item 3 repeats item 2"
     * @param list<PolishedItem> $items  every outline item, polished, in the same order
     */
    public function __construct(
        public array $issues,
        public array $items,
    ) {
    }
}
