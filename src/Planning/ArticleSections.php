<?php

namespace App\Planning;

/**
 * The sections of an article.md body: its top heading level under the title, "## " usually (older articles use "### ").
 * The text before the first heading is the Introduction.
 * A leading "# " title line and the "## " subtitle right under it (see SuggestTitles) belong to no section and are never changed.
 * Deeper headings stay inside their section; a heading inside a fenced code block is not a heading.
 */
final class ArticleSections
{
    public const INTRODUCTION = 'Introduction';

    /**
     * @return array<string, string> heading => section text without its heading line; an empty Introduction is left out
     */
    public static function split(string $body): array
    {
        $sections = [];
        foreach (self::segments($body) as $segment) {
            if (self::INTRODUCTION === $segment['heading'] && '' === trim($segment['text'])) {
                continue;
            }
            $sections[$segment['heading']] ??= $segment['text'];
        }

        return $sections;
    }

    /** Replaces the text of the first section with this heading; the blank lines around it stay as they were. */
    public static function replace(string $body, string $heading, string $newText): string
    {
        $segments = self::segments($body);
        foreach ($segments as $i => $segment) {
            if ($segment['heading'] !== $heading) {
                continue;
            }
            $old = $segment['text'];
            if ('' === trim($old)) {
                [$before, $after] = ['' === $segment['line'] ? '' : "\n", "\n\n"];
            } else {
                preg_match('/\A\s*/', $old, $lead);
                preg_match('/\s*\z/', $old, $trail);
                [$before, $after] = [$lead[0], $trail[0]];
            }
            $segments[$i]['text'] = $before.trim($newText).$after;

            return implode('', array_map(static fn (array $s) => $s['line'].$s['text'], $segments));
        }

        throw new \InvalidArgumentException(\sprintf('The article has no section "%s".', $heading));
    }

    /**
     * @return list<array{heading: string, line: string, text: string}> line is the heading line (or the title + subtitle lines of the Introduction)
     */
    private static function segments(string $body): array
    {
        $lines = preg_split('/(?<=\n)/', $body, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $head = '';
        if (str_starts_with($lines[0] ?? '', '# ')) {
            $head = array_shift($lines);
            if (str_starts_with($lines[0] ?? '', '## ')) {
                $head .= array_shift($lines);
            }
        }

        $levels = [];
        $inFence = false;
        foreach ($lines as $i => $line) {
            if (1 === preg_match('/\A {0,3}(```|~~~)/', $line)) {
                $inFence = !$inFence;
            }
            if (!$inFence && 1 === preg_match('/\A(#{1,6}) /', $line, $hashes)) {
                $levels[$i] = \strlen($hashes[1]);
            }
        }
        $top = [] === $levels ? 2 : min($levels);

        $segments = [['heading' => self::INTRODUCTION, 'line' => $head, 'text' => '']];
        foreach ($lines as $i => $line) {
            if ($top === ($levels[$i] ?? null)) {
                $segments[] = ['heading' => trim(substr($line, $top + 1)), 'line' => $line, 'text' => ''];
                continue;
            }
            $segments[array_key_last($segments)]['text'] .= $line;
        }

        return $segments;
    }
}
