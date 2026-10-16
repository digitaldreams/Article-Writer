<?php

namespace App\Shared\Llm;

enum LlmJobStatus: string
{
    case Queued = 'queued';
    case Working = 'working';
    case Done = 'done';
    case Failed = 'failed';
}
