<?php

namespace App\Planning\Questions;

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
