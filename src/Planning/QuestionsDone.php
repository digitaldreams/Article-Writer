<?php

namespace App\Planning;

/** Fired by AnswerQuestions in the transaction that moves the plan to flows. */
final readonly class QuestionsDone
{
    public function __construct(public int $articleId)
    {
    }
}
