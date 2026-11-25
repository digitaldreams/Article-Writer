<?php

namespace App\Tests\Shared;

use App\Shared\LlmRetryStrategy;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException as AiRuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class LlmRetryStrategyTest extends TestCase
{
    public function testTimeoutIsRetriedOnce(): void
    {
        $strategy = new LlmRetryStrategy();
        $envelope = new Envelope(new \stdClass());
        $failure = new HandlerFailedException($envelope, [new TimeoutException('timed out')]);

        self::assertTrue($strategy->isRetryable($envelope, $failure));
        self::assertFalse($strategy->isRetryable($envelope->with(new RedeliveryStamp(1)), $failure));
    }

    public function testTransportErrorWrappedByTheBridgeIsRetriedOnce(): void
    {
        $strategy = new LlmRetryStrategy();
        $envelope = new Envelope(new \stdClass());
        $wrapped = new AiRuntimeException('Cannot load model', 0, new TransportException('connection refused'));
        $failure = new HandlerFailedException($envelope, [$wrapped]);

        self::assertTrue($strategy->isRetryable($envelope, $failure));
        self::assertFalse($strategy->isRetryable($envelope->with(new RedeliveryStamp(1)), $failure));
    }

    public function testProviderOverloadIsRetriedOnce(): void
    {
        $strategy = new LlmRetryStrategy();
        $envelope = new Envelope(new \stdClass());
        $failure = new HandlerFailedException($envelope, [new ServerException(503, 'high demand')]);

        self::assertTrue($strategy->isRetryable($envelope, $failure));
        self::assertFalse($strategy->isRetryable($envelope->with(new RedeliveryStamp(1)), $failure));
    }

    public function testProviderRateLimitIsRetriedOnce(): void
    {
        $strategy = new LlmRetryStrategy();
        $envelope = new Envelope(new \stdClass());
        $failure = new HandlerFailedException($envelope, [new RateLimitExceededException()]);

        self::assertTrue($strategy->isRetryable($envelope, $failure));
        self::assertFalse($strategy->isRetryable($envelope->with(new RedeliveryStamp(1)), $failure));
    }

    public function testOtherExceptionIsNotRetried(): void
    {
        $envelope = new Envelope(new \stdClass());
        $failure = new HandlerFailedException($envelope, [new \RuntimeException('bad JSON')]);

        self::assertFalse((new LlmRetryStrategy())->isRetryable($envelope, $failure));
    }
}
