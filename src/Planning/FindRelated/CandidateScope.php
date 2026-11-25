<?php

namespace App\Planning\FindRelated;

/**
 * The articles the tools may read during ONE FindRelated::ask(): exactly the candidates the caller passed in.
 * The tool cannot reach anything else, so the writer's corpus is chosen by the caller, never by the model.
 */
// ponytail: shared mutable state, fine for one worker handling one job at a time; use a per-call tool if asks ever overlap.
final class CandidateScope
{
    /** @var array<int, array{id: int, title: string, subtitle?: string, headings?: list<string>}> */
    private array $articles = [];

    /** @param list<array{id: int, title: string, subtitle?: string, headings?: list<string>}> $candidates */
    public function set(array $candidates): void
    {
        $this->articles = array_column($candidates, null, 'id');
    }

    /** @return array{id: int, title: string, subtitle?: string, headings?: list<string>}|null */
    public function get(int $id): ?array
    {
        return $this->articles[$id] ?? null;
    }
}
