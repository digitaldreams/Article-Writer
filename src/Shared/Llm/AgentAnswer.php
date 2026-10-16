<?php

namespace App\Shared\Llm;

use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Reads an agent answer. Reasoning models (e.g. gpt-5.x) answer with a MultiPartResult,
 * a reasoning part next to the text or structured part, so getContent() alone is not enough.
 */
final class AgentAnswer
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function object(Execution $execution, string $class): object
    {
        $result = $execution->getResult();
        $parts = $result instanceof MultiPartResult ? $result->getContent() : [$result];
        foreach ($parts as $part) {
            if ($part instanceof ObjectResult && $part->getContent() instanceof $class) {
                return $part->getContent();
            }
        }

        throw new \RuntimeException(\sprintf('The model did not answer with a %s.', (new \ReflectionClass($class))->getShortName()));
    }

    public static function text(Execution $execution): string
    {
        $result = $execution->getResult();

        return trim(match (true) {
            $result instanceof TextResult => $result->getContent(),
            $result instanceof MultiPartResult => $result->asText(),
            default => throw new \RuntimeException('The model did not answer with text.'),
        });
    }
}
