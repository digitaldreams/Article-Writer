<?php

namespace App\Planning\Brainstorm;

final class UrlLines
{
    /** @return list<string> */
    public static function split(?string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text ?? ''))));
    }
}
