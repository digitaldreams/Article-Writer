<?php

namespace App\Tests\Planning\FindRelated;

use App\Planning\FindRelated\CandidateRetriever;
use PHPUnit\Framework\TestCase;

final class CandidateRetrieverTest extends TestCase
{
    private const ARTICLES = [
        ['id' => 1, 'title' => 'Mastering Symfony Messenger', 'subtitle' => 'Queues and retries', 'headings' => ['Buses', 'Retry strategy']],
        ['id' => 2, 'title' => 'Scaling Redis streams', 'subtitle' => '', 'headings' => []],
        ['id' => 3, 'title' => 'Symfony Routing in Depth', 'subtitle' => 'Attributes', 'headings' => ['Route attributes']],
    ];

    public function testRanksTheArticleThatSharesRareWordsFirst(): void
    {
        $top = (new CandidateRetriever())->top('Async jobs', 'Retry strategy for queues', ['Dead letters'], self::ARTICLES, 2);

        self::assertSame(1, $top[0]['id']);
    }

    public function testReturnsAtMostKAndKeepsTheFullRecords(): void
    {
        $top = (new CandidateRetriever())->top('Symfony', '', [], self::ARTICLES, 2);

        self::assertCount(2, $top);
        self::assertSame('Queues and retries', $top[0]['subtitle'] ?? $top[1]['subtitle']);
    }

    public function testNothingMatchesMeansNothingComesBack(): void
    {
        self::assertSame([], (new CandidateRetriever())->top('Zebra', 'Giraffe', [], self::ARTICLES, 5));
    }

    public function testTiesKeepTheCandidateOrder(): void
    {
        $same = [['id' => 9, 'title' => 'Cache layers'], ['id' => 4, 'title' => 'Cache layers']];

        $top = (new CandidateRetriever())->top('Cache layers', '', [], $same, 5);

        self::assertSame([9, 4], array_column($top, 'id'));
    }
}
