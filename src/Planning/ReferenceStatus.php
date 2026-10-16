<?php

namespace App\Planning;

enum ReferenceStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Unreadable = 'unreadable';
}
