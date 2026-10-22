<?php

namespace App\Planning\EditOutline;

use App\Planning\OutlineItem;
use App\Planning\OutlineItems;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The outline changes only at the outline step, only in its latest round, and not while a round is generating. */
final readonly class OutlineGuard
{
    public function __construct(private OutlineItems $outline, private LlmJobs $jobs)
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
        if ($this->jobs->isRunning($article, 'generate_outline')) {
            throw new ConflictHttpException('A new outline round is being written.');
        }
        if (null !== $item && $item->getRound() !== $this->outline->latestRound($article)) {
            throw new ConflictHttpException('Only the latest outline round can change.');
        }
    }
}
