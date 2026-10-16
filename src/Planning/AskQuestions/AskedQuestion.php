<?php

namespace App\Planning\AskQuestions;

use App\Planning\QuestionRole;

final readonly class AskedQuestion
{
    public function __construct(
        /** Which persona asks it. */
        public QuestionRole $role,
        /** One clear question to the writer. */
        public string $text,
        /** @var list<string> 2 to 4 short choices for a multiple-choice question; empty for an open question. */
        public array $options = [],
    ) {
    }
}
