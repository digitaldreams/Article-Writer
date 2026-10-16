<?php

namespace App\Planning;

enum QuestionRole: string
{
    case Interviewer = 'interviewer';
    case Reader = 'reader';
}
