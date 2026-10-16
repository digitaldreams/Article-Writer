<?php

namespace App\Shared\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    /** Reads a file as it is, e.g. an uploaded reference in refs/. */
    public function readRaw(string $folder, string $file): string
    {
        $content = file_get_contents($this->path($folder, $file));

        return false === $content ? throw new \RuntimeException(sprintf('"%s" can\'t be read.', $file)) : $content;
    }

    /**
     * Stores the upload in refs/ under its sanitized original name. Returns the path relative to the article folder.
     */
    public function storeUpload(string $folder, UploadedFile $file): string
    {
        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $name = (new AsciiSlugger())->slug($original)->toString() ?: 'file';
        if ('' !== $extension) {
            $name .= '.'.(new AsciiSlugger())->slug($extension)->toString();
        }

        $file->move($this->path($folder, 'refs'), $name);

        return 'refs/'.$name;
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
