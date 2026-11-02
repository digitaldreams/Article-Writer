<?php

namespace App\Eval;

/** One judge answer. The reason comes first, so the model argues before it decides. */
final readonly class Verdict
{
    public function __construct(
        /** One or two sentences: which note matches the planted problem, or why none does. */
        public string $reason,
        /** True when at least one note describes the planted problem. */
        public bool $pass,
    ) {
    }
}
