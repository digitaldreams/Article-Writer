<?php

namespace App\Tests\Shared\Article;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Article\InvalidArticleTransition;
use App\Shared\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArticleStateTest extends TestCase
{
    private const ALLOWED = [
        'plan' => ['questions'],
        'questions' => ['flows'],
        'flows' => ['outline'],
        'outline' => ['outline', 'finalize'],
        'finalize' => ['outline', 'flows', 'approved'],
        'approved' => ['written'],
        'written' => [],
        'imported' => [],
    ];

    /**
     * @return iterable<string, array{ArticleState, ArticleState, bool}>
     */
    public static function everyMove(): iterable
    {
        foreach (ArticleState::cases() as $from) {
            foreach (ArticleState::cases() as $to) {
                $allowed = \in_array($to->value, self::ALLOWED[$from->value], true);
                yield sprintf('%s to %s %s', $from->value, $to->value, $allowed ? 'passes' : 'throws') => [$from, $to, $allowed];
            }
        }
    }

    #[DataProvider('everyMove')]
    public function testMoveFollowsTheTransitionMap(ArticleState $from, ArticleState $to, bool $allowed): void
    {
        $article = self::articleIn($from);

        if (!$allowed) {
            $this->expectException(InvalidArticleTransition::class);
        }
        $article->moveTo($to);

        $this->assertSame($to, $article->getState());
    }

    public function testNewArticlesStartInPlanOrImported(): void
    {
        $this->assertSame(ArticleState::Plan, Article::plan(new User('a@example.com'), 's', 't')->getState());
        $this->assertSame(ArticleState::Imported, Article::imported(new User('a@example.com'), 's', 't')->getState());
    }

    private static function articleIn(ArticleState $state): Article
    {
        $article = ArticleState::Imported === $state ? Article::imported(new User('a@example.com'), 's', 't') : Article::plan(new User('a@example.com'), 's', 't');
        $path = [
            'plan' => [], 'questions' => ['questions'], 'flows' => ['questions', 'flows'],
            'outline' => ['questions', 'flows', 'outline'],
            'finalize' => ['questions', 'flows', 'outline', 'finalize'],
            'approved' => ['questions', 'flows', 'outline', 'finalize', 'approved'],
            'written' => ['questions', 'flows', 'outline', 'finalize', 'approved', 'written'],
            'imported' => [],
        ][$state->value];
        foreach ($path as $step) {
            $article->moveTo(ArticleState::from($step));
        }

        return $article;
    }
}
