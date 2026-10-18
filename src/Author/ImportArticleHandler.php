<?php

namespace App\Author;

use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Yaml\Yaml;

#[AsMessageHandler(fromTransport: 'io')]
final readonly class ImportArticleHandler
{
    public function __construct(private ArticleFolder $articleFolder, private EntityManagerInterface $em)
    {
    }

    public function __invoke(ImportArticle $message): void
    {
        $post = match ($message->format) {
            'medium' => $this->medium($message),
            'html' => $this->html($message),
            'md' => $this->markdown($message),
        };
        if (null === $post) {
            return;
        }
        [$frontMatter, $body, $title, $sourceUrl, $publishedAt] = $post;

        $user = ($this->em->find(LlmJob::class, $message->llmJobId()) ?? throw new \RuntimeException('The job is gone.'))->getUser();
        // Workers have no owner filter: scope the lookup by the job's author.
        if (null !== $sourceUrl && null !== $this->em->getRepository(Article::class)->findOneBy(['user' => $user, 'sourceUrl' => $sourceUrl])) {
            return;
        }

        $this->em->wrapInTransaction(function () use ($frontMatter, $body, $title, $user, $sourceUrl, $publishedAt): void {
            $article = Article::imported($user, ArticleFolder::slug($title), $title, $sourceUrl, $publishedAt);
            $this->em->persist($article);
            $this->em->flush();
            $this->articleFolder->write($article->getFolder(), 'article.md', $this->plainDates($frontMatter) + ['title' => $title], $body);
        });
    }

    /** @return array{array<string, mixed>, string, string, ?string, ?\DateTimeImmutable} */
    private function markdown(ImportArticle $message): array
    {
        $content = str_replace("\r\n", "\n", $message->content);
        if ('' === trim($content)) {
            throw new \RuntimeException('The file is empty.');
        }
        [$frontMatter, $body] = $this->split($content);

        return [
            $frontMatter,
            $body,
            $this->title($frontMatter, $body, $message->filename),
            self::sourceKey($message->sourceUrl ?? ''),
            self::date($frontMatter['date'] ?? null) ?? $message->publishedAt,
        ];
    }

    /**
     * One posts/*.html of a Medium export; null for a response.
     *
     * @return array{array<string, mixed>, string, string, ?string, ?\DateTimeImmutable}|null
     */
    private function medium(ImportArticle $message): ?array
    {
        $doc = \Dom\HTMLDocument::createFromString($message->content, LIBXML_NOERROR);
        $body = $doc->querySelector('.e-content') ?? throw new \RuntimeException('Not a Medium post: no .e-content.');
        $subtitle = trim($body->querySelector('.graf--subtitle')->textContent ?? '');
        // The body repeats the title and subtitle as its first lines.
        foreach ($body->querySelectorAll('.graf--title, .graf--subtitle') as $node) {
            $node->remove();
        }
        $markdown = self::markdownOf($doc, $body, skipResponses: true);
        if (null === $markdown) {
            return null;
        }

        $canonical = $doc->querySelector('.p-canonical')?->getAttribute('href')
            ?? (1 === preg_match('/-([0-9a-f]{12})\.html$/', $message->filename, $m) ? 'https://medium.com/p/'.$m[1] : '');
        $publishedAt = self::date($doc->querySelector('.dt-published')?->getAttribute('datetime'));
        $title = trim($doc->querySelector('.p-name')->textContent ?? '') ?: pathinfo($message->filename, PATHINFO_FILENAME);

        return [
            array_filter(['subtitle' => $subtitle, 'date' => $publishedAt?->format('Y-m-d')]),
            $markdown,
            $title,
            self::sourceKey($canonical, medium: true),
            $publishedAt,
        ];
    }

    /**
     * One feed item: the HTML body, its title in filename.
     *
     * @return array{array<string, mixed>, string, string, ?string, ?\DateTimeImmutable}|null
     */
    private function html(ImportArticle $message): ?array
    {
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$message->content, LIBXML_NOERROR);
        if ('' === trim((string) $doc->body?->textContent)) {
            throw new \RuntimeException('The item is empty.');
        }
        $sourceUrl = self::sourceKey($message->sourceUrl ?? '');
        // Only Medium mixes comments into the feed; a short item elsewhere is a summary or a short post.
        $markdown = self::markdownOf($doc, $doc->body ?? throw new \RuntimeException('The item has no body.'), str_starts_with((string) $sourceUrl, 'https://medium.com/p/'));
        if (null === $markdown) {
            return null;
        }

        return [
            array_filter(['date' => $message->publishedAt?->format('Y-m-d')]),
            $markdown,
            '' === trim($message->filename) ? 'Untitled' : trim($message->filename),
            $sourceUrl,
            $message->publishedAt,
        ];
    }

    /** HTML body → markdown. Null for a Medium response (a comment): Medium marks nothing, so no h2–h4 and under 150 words counts as one. */
    private static function markdownOf(\Dom\HTMLDocument $doc, \Dom\Element $body, bool $skipResponses): ?string
    {
        // ponytail: heuristic, a short real post is skipped too; re-import it as .md.
        if ($skipResponses && null === $body->querySelector('h2, h3, h4') && str_word_count((string) $body->textContent) < 150) {
            return null;
        }
        // Medium writes code lines as <pre>line<br>line</pre>, with markup (bold, links, spans) inside: keep the plain text only.
        foreach ($body->querySelectorAll('pre br') as $br) {
            $br->replaceWith("\n");
        }
        foreach ($body->querySelectorAll('pre') as $pre) {
            $pre->textContent = $pre->textContent;
        }
        // Figures go: an image carries no voice, and a stripped <figcaption> would glue onto the next heading.
        $converter = new HtmlConverter(['strip_tags' => true, 'header_style' => 'atx', 'remove_nodes' => 'script style figure']);

        return $converter->convert($doc->saveHtml($body))."\n";
    }

    /**
     * One post, one key: a Medium post is medium.com/p/<id> whether it came from the export or the feed; other links lose fragment and utm_ tracking; non-http links are dropped (they end up in an href).
     *
     * @param bool $medium the link is a Medium post even off medium.com (a publication on its own domain)
     */
    public static function sourceKey(string $url, bool $medium = false): ?string
    {
        if (1 !== preg_match('~^https?://~i', $url)) {
            return null;
        }
        [$base, $query] = explode('?', (string) strtok($url, '#'), 2) + [1 => ''];
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        if (($medium || 'medium.com' === $host || str_ends_with($host, '.medium.com')) && 1 === preg_match('~[-/]([0-9a-f]{12})/?$~', $base, $m)) {
            return 'https://medium.com/p/'.$m[1];
        }
        // Keep the query (?p=123 is the post), drop the tracking.
        $kept = array_filter(explode('&', $query), static fn (string $pair): bool => '' !== $pair && !str_starts_with($pair, 'utm_'));

        return $base.([] === $kept ? '' : '?'.implode('&', $kept));
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        try {
            return \is_string($value) ? new \DateTimeImmutable($value) : null;
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array{array<string, mixed>, string} */
    private function split(string $content): array
    {
        if (1 !== preg_match('/\A---\n(.*?)^---\n(.*)\z/ms', $content, $m)) {
            return [[], $content];
        }
        $frontMatter = Yaml::parse($m[1], Yaml::PARSE_DATETIME);

        return [\is_array($frontMatter) ? $frontMatter : [], $m[2]];
    }

    /**
     * PARSE_DATETIME turns `date: 2024-03-05` into a DateTime; write the author's value back as written.
     *
     * @param array<string, mixed> $frontMatter
     *
     * @return array<string, mixed>
     */
    private function plainDates(array $frontMatter): array
    {
        return array_map(static fn ($v) => $v instanceof \DateTimeInterface
            ? $v->format('00:00:00' === $v->format('H:i:s') ? 'Y-m-d' : 'c')
            : $v, $frontMatter);
    }

    /** @param array<string, mixed> $frontMatter */
    private function title(array $frontMatter, string $body, string $filename): string
    {
        if (\is_string($frontMatter['title'] ?? null) && '' !== trim($frontMatter['title'])) {
            return trim($frontMatter['title']);
        }
        if (1 === preg_match('/^# +(.+?)\s*$/m', $body, $m)) {
            return $m[1];
        }

        return pathinfo($filename, PATHINFO_FILENAME);
    }
}
