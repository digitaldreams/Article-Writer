<?php

namespace App\Planning\FindRelated;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('read_article', 'Subtitle and section headings of one of the writer\'s older articles, by its id from the list. Use it when a title alone does not tell if the article fits.')]
final readonly class ReadArticle
{
    public function __construct(private CandidateScope $scope)
    {
    }

    /** @param int $id the id from the list of older articles */
    public function __invoke(int $id): string
    {
        $article = $this->scope->get($id);
        if (null === $article) {
            return 'No such article.';
        }
        $headings = $article['headings'] ?? [];

        return \sprintf("%s\nSubtitle: %s\n%s", $article['title'], $article['subtitle'] ?? '', [] === $headings ? '(no headings)' : "Section headings:\n- ".implode("\n- ", $headings));
    }
}
