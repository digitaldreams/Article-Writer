<?php

namespace App\Shared\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Yaml\Yaml;

final readonly class ArticleFolder
{
    private Filesystem $fs;

    public function __construct(
        #[Autowire('%env(resolve:ARTICLES_DIR)%')]
        private string $articlesDir,
    ) {
        $this->fs = new Filesystem();
    }

    /** Creates the article folder, named by the article id (Article::getFolder()), under ARTICLES_DIR. */
    public function create(string $folder): void
    {
        $this->fs->mkdir($this->path($folder));
    }

    public static function slug(string $title): string
    {
        return (new AsciiSlugger())->slug($title)->lower()->toString();
    }

    /**
     * @param array<string, mixed> $frontMatter
     */
    public function write(string $folder, string $file, array $frontMatter, string $body): void
    {
        $this->fs->dumpFile(
            $this->path($folder, $file),
            "---\n".Yaml::dump($frontMatter)."---\n".$body,
        );
    }

    /**
     * @return array{frontMatter: array<string, mixed>, body: string}
     */
    public function read(string $folder, string $file): array
    {
        $content = file_get_contents($this->path($folder, $file));
        if (false === $content || 1 !== preg_match('/\A---\n(.*?)^---\n(.*)\z/ms', $content, $m)) {
            throw new \RuntimeException(sprintf('"%s" has no front matter.', $file));
        }
        $frontMatter = Yaml::parse($m[1]);

        return ['frontMatter' => \is_array($frontMatter) ? $frontMatter : [], 'body' => $m[2]];
    }

    /** Absolute path of a file in an article folder, e.g. to show the writer where article.md is. */
    public function pathOf(string $folder, string $file): string
    {
        return $this->path($folder, $file);
    }

    private function path(string ...$parts): string
    {
        return $this->articlesDir.'/'.implode('/', $parts);
    }
}
