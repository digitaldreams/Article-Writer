<?php

namespace App\Tests\Planning\FindRelated;

use App\Planning\FindRelated\CandidateScope;
use App\Planning\FindRelated\ReadArticle;
use PHPUnit\Framework\TestCase;

final class ReadArticleTest extends TestCase
{
    public function testReturnsSubtitleAndHeadingsOfAnArticleInScope(): void
    {
        $scope = new CandidateScope();
        $scope->set([['id' => 7, 'title' => 'Messenger', 'subtitle' => 'Queues in PHP', 'headings' => ['Buses', 'Retries']]]);

        $text = (new ReadArticle($scope))(7);

        self::assertStringContainsString('Queues in PHP', $text);
        self::assertStringContainsString('- Buses', $text);
        self::assertStringContainsString('- Retries', $text);
    }

    public function testAnIdOutsideTheScopeReadsLikeAnIdThatDoesNotExist(): void
    {
        $scope = new CandidateScope();
        $scope->set([['id' => 7, 'title' => 'Messenger', 'subtitle' => 'secret-subtitle', 'headings' => ['secret-heading']]]);

        $outside = (new ReadArticle($scope))(8);

        self::assertSame('No such article.', $outside);
        self::assertSame($outside, (new ReadArticle(new CandidateScope()))(7), 'an empty scope answers the same way: no hint that 7 exists elsewhere');
    }

    public function testASecondAskReplacesTheScope(): void
    {
        $scope = new CandidateScope();
        $scope->set([['id' => 7, 'title' => 'A', 'subtitle' => '', 'headings' => []]]);
        $scope->set([['id' => 9, 'title' => 'B', 'subtitle' => '', 'headings' => []]]);

        self::assertSame('No such article.', (new ReadArticle($scope))(7));
    }

    public function testAnArticleWithoutHeadingsSaysSo(): void
    {
        $scope = new CandidateScope();
        $scope->set([['id' => 1, 'title' => 'A']]);

        self::assertStringContainsString('no headings', (new ReadArticle($scope))(1));
    }
}
