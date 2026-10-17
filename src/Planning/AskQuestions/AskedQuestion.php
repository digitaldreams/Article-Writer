<?php

namespace App\Planning\AskQuestions;

final readonly class AskedQuestion
{
    public function __construct(
        /** One clear question to the writer. */
        public string $text,
        /** @var list<string> 2 to 5 short choices; empty for an open question. */
        public array $options = [],
        /** True when the writer may pick several options; false when exactly one fits. */
        public bool $multiple = false,
    ) {
    }
}
