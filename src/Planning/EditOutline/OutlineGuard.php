<?php

namespace App\Planning\EditOutline;

use App\Planning\OutlineItem;
use App\Planning\OutlineItems;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The outline changes only at the outline step, and only in its latest round. */
final readonly class OutlineGuard
{
    public function __construct(private OutlineItems $outline)
    {
    }

    public function assertEditable(Article $article, ?OutlineItem $item = null): void
    {
        if (null !== $item && $item->getArticle() !== $article) {
            throw new NotFoundHttpException();
        }
        if (ArticleState::Outline !== $article->getState()) {
            throw new ConflictHttpException('The outline can only change at the outline step.');
        }
        if (null !== $item && $item->getRound() !== $this->outline->latestRound($article)) {
            throw new ConflictHttpException('Only the latest outline round can change.');
        }
    }
}
