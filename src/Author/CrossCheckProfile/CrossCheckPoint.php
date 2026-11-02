<?php

namespace App\Author\CrossCheckProfile;

final readonly class CrossCheckPoint
{
    public function __construct(
        /** The field key the point is about, exactly as in the profile, e.g. 'openingType'. */
        public string $field,
        /** What breaks the rule, quoting the writer's words in single quotes and naming the rule. */
        public string $issue,
        /** One concrete change the writer can make. */
        public string $suggestion,
    ) {
    }
}
