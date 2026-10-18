<?php

namespace App\Shared\Article;

enum ArticleState: string
{
    case Plan = 'plan';
    case Questions = 'questions';
    case Outline = 'outline';
    case Approved = 'approved';
    case Written = 'written';
    case Imported = 'imported';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            // Questions are optional: a solid idea goes straight to the outline.
            self::Plan => [self::Questions, self::Outline],
            self::Questions => [self::Outline],
            self::Outline => [self::Outline, self::Approved],
            self::Approved => [self::Written],
            self::Written, self::Imported => [],
        };
    }
}
