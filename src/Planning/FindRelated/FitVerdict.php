<?php

namespace App\Planning\FindRelated;

final readonly class FitVerdict
{
    public function __construct(
        /** One short sentence on how the older article relates to the one in progress, or why it does not. Written before the verdict. */
        public string $reason,
        /** True only if the reader of the article in progress should be pointed to the older article. */
        public bool $fits,
    ) {
    }
}
