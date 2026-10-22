<?php

namespace App\Planning\FinalCheck;

use App\Shared\Article\Article;
use App\Shared\Storage\ArticleFolder;

/** idea.md and the approved outline.md the article was written from; an imported article has neither. */
final readonly class PlanFiles
{
    public function __construct(private ArticleFolder $folder)
    {
    }

    /** @return array{idea: ?string, outline: ?string} */
    public function of(Article $article): array
    {
        $read = fn (string $file) => file_exists($this->folder->pathOf($article->getFolder(), $file)) ? $this->folder->read($article->getFolder(), $file)['body'] : null;

        return ['idea' => $read('idea.md'), 'outline' => $read('outline.md')];
    }
}
