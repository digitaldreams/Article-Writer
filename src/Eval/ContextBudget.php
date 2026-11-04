<?php

namespace App\Eval;

/** What one model call reads, part by part: characters, share and an estimated token count. */
final readonly class ContextBudget
{
    // ponytail: no tokenizer. 4.8 = the baseline's 8,300 characters / 1,726 input tokens per call; calibrate per model.
    public const CHARS_PER_TOKEN = 4.8;

    /** @var array<string, int> characters per part, biggest first */
    private array $chars;

    /** @param array<string, string> $parts name => text */
    public function __construct(array $parts, private float $charsPerToken = self::CHARS_PER_TOKEN)
    {
        $chars = array_map(mb_strlen(...), $parts);
        arsort($chars);
        $this->chars = $chars;
    }

    /** @return array<string, int> */
    public function parts(): array
    {
        return $this->chars;
    }

    public function total(): int
    {
        return array_sum($this->chars);
    }

    public function share(string $part): float
    {
        return 0 === $this->total() ? 0.0 : $this->chars[$part] / $this->total();
    }

    public function tokens(string $part): int
    {
        return (int) round($this->chars[$part] / $this->charsPerToken);
    }
}
