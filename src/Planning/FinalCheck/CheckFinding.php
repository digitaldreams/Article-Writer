<?php

namespace App\Planning\FinalCheck;

final readonly class CheckFinding
{
    public function __construct(
        /** The exact "## " heading of the section the note is about, or "General" for the whole article. */
        public string $section,
        /** What is inconsistent, missing or wrong, quoting the text or code it is about. */
        public string $problem,
        /** One concrete change that fixes it. */
        public string $suggestion,
    ) {
    }
}
