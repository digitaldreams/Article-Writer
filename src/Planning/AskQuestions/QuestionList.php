<?php

namespace App\Planning\AskQuestions;

/** Structured answer of AskQuestions. */
final readonly class QuestionList
{
    /**
     * @param list<AskedQuestion> $questions at most 5, asked by the reader
     */
    public function __construct(
        public array $questions,
    ) {
    }
}
