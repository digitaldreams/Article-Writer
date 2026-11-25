<?php

namespace App\Planning;

/**
 * The sections of an article.md body: its top heading level under the title, "## " usually (older articles use "### ").
 * The text before the first heading is the Introduction.
 * A leading "# " title line and the "## " subtitle right under it (see Titles) belong to no section and are never changed.
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
        foreach (self::sections($body) as $section) {
            $sections[$section['heading']] ??= $section['text'];
        }

        return $sections;
    }

    /** The "# " title and "## " subtitle lines at the top, or ''. They belong to no section. */
    public static function head(string $body): string
    {
        return self::segments($body)[0]['line'];
    }

    /**
     * The sections in order, for reading them one by one; an empty Introduction is left out.
     *
     * @return list<array{heading: string, line: string, text: string}> line is the heading line ('' for the Introduction)
     */
    public static function sections(string $body): array
    {
        $segments = self::segments($body);
        $segments[0]['line'] = '';

        return array_values(array_filter($segments, static fn (array $s) => self::INTRODUCTION !== $s['heading'] || '' !== trim($s['text'])));
    }

    /** 1-based place of the first section with this heading, for the #section-N anchor; 0 when it is gone. */
    public static function number(string $body, string $heading): int
    {
        $headings = array_column(self::sections($body), 'heading');
        $at = array_search($heading, $headings, true);

        return false === $at ? 0 : $at + 1;
    }

    /** Replaces the text of the first section with this heading; the blank lines around it stay as they were. */
    public static function replace(string $body, string $heading, string $newText): string
    {
        $segments = self::segments($body);

        return self::join($segments, self::at($segments, $heading), $newText);
    }

    /**
     * Replaces a whole section with what the author typed: its heading line, then its text (the Introduction has no heading line).
     * A changed heading line renames the section.
     *
     * @return array{body: string, heading: string} heading is the section's heading after the edit
     */
    public static function edit(string $body, string $heading, string $markdown): array
    {
        if (self::INTRODUCTION === $heading) {
            return ['body' => self::replace($body, $heading, $markdown), 'heading' => $heading];
        }
        $segments = self::segments($body);
        $i = self::at($segments, $heading);
        $hashes = strstr($segments[$i]['line'], ' ', true);
        $markdown = ltrim($markdown);
        if (1 !== preg_match('/\A'.$hashes.' (.*\S)[ \t]*(?:\n|\z)/', $markdown, $line)) {
            throw new \InvalidArgumentException(\sprintf('Start the section with its "%s " heading line.', $hashes));
        }
        // Sections are found by heading: a second "Setup" would make the next save overwrite the first one.
        if ($line[1] !== $heading && \in_array($line[1], array_column($segments, 'heading'), true)) {
            throw new \InvalidArgumentException(\sprintf('Another section is already called "%s".', $line[1]));
        }
        $segments[$i]['line'] = $hashes.' '.$line[1]."\n";

        return ['body' => self::join($segments, $i, substr($markdown, \strlen($line[0]))), 'heading' => $line[1]];
    }

    /** @param list<array{heading: string, line: string, text: string}> $segments */
    private static function at(array $segments, string $heading): int
    {
        foreach ($segments as $i => $segment) {
            if ($segment['heading'] === $heading) {
                return $i;
            }
        }

        throw new \InvalidArgumentException(\sprintf('The article has no section "%s".', $heading));
    }

    /** @param list<array{heading: string, line: string, text: string}> $segments */
    private static function join(array $segments, int $i, string $newText): string
    {
        $old = $segments[$i]['text'];
        if ('' === trim($old)) {
            [$before, $after] = ['' === $segments[$i]['line'] ? '' : "\n", "\n\n"];
        } else {
            preg_match('/\A\s*/', $old, $lead);
            preg_match('/\s*\z/', $old, $trail);
            [$before, $after] = [$lead[0], $trail[0]];
        }
        $segments[$i]['text'] = $before.trim($newText).$after;

        return implode('', array_map(static fn (array $s) => $s['line'].$s['text'], $segments));
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
