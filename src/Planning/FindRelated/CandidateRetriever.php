<?php

namespace App\Planning\FindRelated;

/**
 * Cuts the writer's whole corpus down to the few candidates worth sending to the model: word overlap between the article in
 * progress and each older article's title, subtitle and headings, rarer words counting more. No model, no embeddings, no cost.
 */
// ponytail: plain word overlap. Misses synonyms ("queue" vs "message bus"); swap in embeddings only if a measured recall@K says so.
final class CandidateRetriever
{
    private const STOP = ['the', 'and', 'for', 'with', 'from', 'how', 'your', 'you', 'are', 'into', 'using', 'use', 'what', 'why', 'when', 'part', 'guide', 'mastering'];

    /**
     * @param list<string>                                                                          $headings
     * @param list<array{id: int, title: string, subtitle?: string, headings?: list<string>}> $articles
     *
     * @return list<array{id: int, title: string, subtitle?: string, headings?: list<string>}> at most $k, best first, none with a zero score
     */
    public function top(string $title, string $subtitle, array $headings, array $articles, int $k): array
    {
        $query = self::words($title.' '.$subtitle.' '.implode(' ', $headings));
        $docs = array_map(static fn (array $a) => self::words($a['title'].' '.($a['subtitle'] ?? '').' '.implode(' ', $a['headings'] ?? [])), $articles);
        $df = [];
        foreach ($docs as $words) {
            foreach (array_keys($words) as $w) {
                $df[$w] = ($df[$w] ?? 0) + 1;
            }
        }
        $scores = [];
        foreach ($docs as $i => $words) {
            $scores[$i] = 0.0;
            foreach (array_intersect_key($words, $query) as $w => $_) {
                $scores[$i] += log(1 + \count($articles) / $df[$w]);
            }
        }
        $order = array_keys(array_filter($scores));
        usort($order, static fn (int $a, int $b) => $scores[$b] <=> $scores[$a] ?: $a <=> $b);

        return array_map(static fn (int $i) => $articles[$i], \array_slice($order, 0, $k));
    }

    /** @return array<string, true> */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $m);

        return array_fill_keys(array_diff($m[0], self::STOP), true);
    }
}
