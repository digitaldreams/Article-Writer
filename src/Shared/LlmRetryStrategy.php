<?php

namespace App\Shared;

use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/** Retries an LLM job once, only for a temporary failure: the connection, a timeout, or a provider overload or rate limit. */
final class LlmRetryStrategy implements RetryStrategyInterface
{
    private const MAX_RETRIES = 1;
    private const DELAY_MS = 5000;

    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        if ($throwable instanceof HandlerFailedException) {
            $throwable = $throwable->getWrappedExceptions()[0] ?? $throwable;
        }

        return $this->isTemporaryFailure($throwable)
            && RedeliveryStamp::getRetryCountFromEnvelope($message) < self::MAX_RETRIES;
    }

    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        return self::DELAY_MS;
    }

    /** Bridges wrap the HTTP error (e.g. Ollama's model lookup), so look through the whole chain. */
    private function isTemporaryFailure(?\Throwable $throwable): bool
    {
        for (; null !== $throwable; $throwable = $throwable->getPrevious()) {
            if ($throwable instanceof TransportExceptionInterface || $throwable instanceof RetryableExceptionInterface) {
                return true;
            }
        }

        return false;
    }
}
