<?php

namespace App\Planning\FindRelated;

final readonly class RelatedLink
{
    public function __construct(
        /** The id of the older article, exactly as it appears in the list. */
        public int $id,
        /** One short sentence: what the reader gets from that article. */
        public string $reason,
    ) {
    }
}
