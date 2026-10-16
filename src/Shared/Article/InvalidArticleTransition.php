<?php

namespace App\Shared\Article;

final class InvalidArticleTransition extends \DomainException
{
    public function __construct(ArticleState $from, ArticleState $to)
    {
        parent::__construct(sprintf('Article cannot move from "%s" to "%s".', $from->value, $to->value));
    }
}
