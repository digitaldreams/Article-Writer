<?php

namespace App\Setup\ImportArticles;

use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Yaml\Yaml;

#[AsMessageHandler(fromTransport: 'io')]
final readonly class ImportFileHandler
{
    public function __construct(private ArticleFolder $articleFolder, private EntityManagerInterface $em, private LlmJobs $jobs)
    {
    }

    public function __invoke(ImportFile $message): void
    {
        $content = str_replace("\r\n", "\n", $message->content);
        if ('' === trim($content)) {
            throw new \RuntimeException('The file is empty.');
        }

        [$frontMatter, $body] = $this->split($content);
        $title = $this->title($frontMatter, $body, $message->filename);

        $user = ($this->em->find(LlmJob::class, $message->llmJobId()) ?? throw new \RuntimeException('The job is gone.'))->getUser();
        $this->em->wrapInTransaction(function () use ($frontMatter, $body, $title, $user): void {
            $article = Article::imported($user, ArticleFolder::slug($title), $title);
            $this->em->persist($article);
            $this->em->flush();
            $this->articleFolder->write($article->getFolder(), 'article.md', $this->plainDates($frontMatter) + ['title' => $title], $body);
            $this->jobs->start($article, 'extract_structure_notes', 'secondary', static fn (int $id) => new ExtractStructureNotes($id, (int) $article->getId()));
        });
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
