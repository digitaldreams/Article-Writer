<?php

namespace App\Shared\Article;

enum ArticleState: string
{
    case Plan = 'plan';
    case Questions = 'questions';
    case Flows = 'flows';
    case Outline = 'outline';
    case Finalize = 'finalize';
    case Approved = 'approved';
    case Written = 'written';
    case Imported = 'imported';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Plan => [self::Questions],
            self::Questions => [self::Flows],
            self::Flows => [self::Outline],
            self::Outline => [self::Outline, self::Finalize],
            self::Finalize => [self::Outline, self::Flows, self::Approved],
            self::Approved => [self::Written],
            self::Written, self::Imported => [],
        };
    }
}
