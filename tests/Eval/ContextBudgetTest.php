<?php

namespace App\Tests\Eval;

use App\Eval\ContextBudget;
use PHPUnit\Framework\TestCase;

final class ContextBudgetTest extends TestCase
{
    public function testShareIsPartOverTotal(): void
    {
        $budget = new ContextBudget(['prompt' => str_repeat('a', 100), 'article' => str_repeat('b', 900)]);

        self::assertSame(1000, $budget->total());
        self::assertEqualsWithDelta(0.1, $budget->share('prompt'), 1e-9);
        self::assertEqualsWithDelta(0.9, $budget->share('article'), 1e-9);
    }

    public function testCharactersCountMultibyteAsOne(): void
    {
        self::assertSame(3, (new ContextBudget(['a' => 'äöü']))->total());
    }

    public function testTokensAreAnEstimateFromCharsPerToken(): void
    {
        $budget = new ContextBudget(['a' => str_repeat('x', 480)], charsPerToken: 4.8);

        self::assertSame(100, $budget->tokens('a'));
    }

    public function testEmptyBudgetHasNoShare(): void
    {
        self::assertEqualsWithDelta(0.0, (new ContextBudget(['a' => '']))->share('a'), 1e-9);
    }

    public function testBiggestPartComesFirst(): void
    {
        $budget = new ContextBudget(['small' => 'a', 'big' => 'bbbb', 'mid' => 'cc']);

        self::assertSame(['big', 'mid', 'small'], array_keys($budget->parts()));
    }
}
