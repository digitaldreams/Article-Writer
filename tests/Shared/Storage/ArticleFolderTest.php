<?php

namespace App\Tests\Shared\Storage;

use App\Shared\Storage\ArticleFolder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ArticleFolderTest extends TestCase
{
    private string $dir;
    private ArticleFolder $folder;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/article-folder-'.bin2hex(random_bytes(4));
        $this->folder = new ArticleFolder($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testTheFolderIsCreatedUnderArticlesDir(): void
    {
        $this->folder->create('42');

        $this->assertDirectoryExists($this->dir.'/42');
    }

    public function testSlugIsTheLowercaseAsciiTitle(): void
    {
        $this->assertSame('symfony-messenger-cafe', ArticleFolder::slug('Symfony Messenger: Café!'));
    }

    public function testWrittenMdFileReadsBackWithSameFrontMatterAndBody(): void
    {
        $name = '7';
        $this->folder->create($name);
        $frontMatter = ['plan_id' => 7, 'title' => 'A: tricky "title"'];
        $body = "Intro\n\n---\n\n## Section\nText\n";

        $this->folder->write($name, 'article.md', $frontMatter, $body);

        $this->assertSame(['frontMatter' => $frontMatter, 'body' => $body], $this->folder->read($name, 'article.md'));
    }

    public function testUploadIsStoredInRefsUnderSanitizedName(): void
    {
        $name = '7';
        $this->folder->create($name);
        $tmp = $this->dir.'/upload.tmp';
        (new Filesystem())->dumpFile($tmp, 'data');

        $path = $this->folder->storeUpload($name, new UploadedFile($tmp, '../My Notes (v2).PDF', null, null, true));

        $this->assertSame('refs/My-Notes-v2.pdf', $path);
        $this->assertFileExists($this->dir.'/'.$name.'/refs/My-Notes-v2.pdf');
    }
}
