<?php

namespace App\Tests\Author\ImportArticles;

use App\Author\ImportArticle;
use App\Author\ImportArticleHandler;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\User\User;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class ImportArticlesTest extends WebTestCase
{
    use LogsInAuthor;

    public function testThreeUploadedFilesBecomeThreeFoldersAndImportedArticles(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $tag = uniqid('t');
        $this->upload($client, [
            'a.md' => "---\ntitle: First $tag\ndate: 2024-03-05\n---\nBody one\n",
            'b.md' => "# Second $tag\n\nNo front matter\n",
            "c$tag.md" => "Just text\n",
        ]);

        self::assertResponseRedirects('/author', 303);
        $this->consume();

        $articles = $this->em()->getRepository(Article::class)->findBy(['state' => ArticleState::Imported]);
        self::assertCount(3, $articles);
        self::assertEqualsCanonicalizing(["First $tag", "Second $tag", "c$tag"], array_map(static fn (Article $a) => $a->getTitle(), $articles));
        foreach ($articles as $article) {
            self::assertSame((string) $article->getId(), $article->getFolder());
            self::assertFileExists(self::getContainer()->getParameter('kernel.project_dir').'/var/test-articles/'.$article->getFolder().'/article.md');
        }
        foreach ($this->em()->getRepository(LlmJob::class)->findBy(['step' => 'import_file']) as $job) {
            self::assertSame(LlmJobStatus::Done, $job->getStatus());
        }
    }

    public function testTheFrontMatterDateIsKeptAsWritten(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $tag = uniqid('m');
        $ms = (new \DateTimeImmutable('2023-06-15 12:00:00 UTC'))->getTimestamp() * 1000;
        $this->upload($client, ["m$tag.md" => "# M $tag\nx\n", "k$tag.md" => "---\ntitle: K $tag\ndate: 2022-01-02\n---\nx\n"], [
            "m$tag.md" => $ms,
            "k$tag.md" => $ms,
        ]);
        $this->consume();

        $k = $this->em()->getRepository(Article::class)->findOneBy(['title' => "K $tag"]) ?? throw new \LogicException('No K.');
        $md = file_get_contents(self::getContainer()->getParameter('kernel.project_dir').'/var/test-articles/'.$k->getFolder().'/article.md');
        self::assertStringContainsString('date: ', $md);
        self::assertMatchesRegularExpression('/^date: .?2022-01-02.?$/m', $md);
    }

    public function testTwoFilesWithTheSameTitleGetTheirOwnFolders(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $tag = uniqid('d');
        $this->upload($client, ["one.md" => "# Same $tag\nx\n", "two.md" => "# Same $tag\ny\n"]);
        $this->consume();

        $folders = array_map(static fn (Article $a) => $a->getFolder(), $this->em()->getRepository(Article::class)->findBy(['title' => "Same $tag"]));
        self::assertCount(2, array_unique($folders));
    }

    public function testFailedFileIsShownByNameWithRetry(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->upload($client, ['empty-one.md' => "  \n"]);
        $this->consume();

        $crawler = $client->request('GET', '/author');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('empty-one.md', $crawler->filter('.import-file')->text());
        self::assertStringContainsString('The file is empty.', $crawler->filter('.job-error')->text());
        self::assertCount(1, $crawler->selectButton('Retry'));
    }

    public function testTheFrontMatterDateWinsOverTheFileDate(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $tag = uniqid('p');
        $ms = (new \DateTimeImmutable('2023-06-15 12:00:00 UTC'))->getTimestamp() * 1000;
        $this->upload($client, ["m$tag.md" => "# M $tag\nx\n", "k$tag.md" => "---\ntitle: K $tag\ndate: 2022-01-02\n---\nx\n"], ["m$tag.md" => $ms, "k$tag.md" => $ms]);
        $this->consume();

        self::assertSame('2023-06-15', $this->article("M $tag")->getPublishedAt()?->format('Y-m-d'));
        self::assertSame('2022-01-02', $this->article("K $tag")->getPublishedAt()?->format('Y-m-d'));
    }

    public function testAnMdImportAppearsInTheLibrary(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $tag = uniqid('l');
        $this->upload($client, ["l$tag.md" => "# Library $tag\nx\n"]);
        $this->consume();

        $client->request('GET', '/author/library');
        self::assertSelectorTextContains('.library-article', "Library $tag");
    }

    public function testTheSameSourceUrlImportedTwiceIsOneArticle(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->import("# Once\nx\n", 'https://example.com/once?utm_source=a');
        $this->import("# Once\nx\n", 'https://example.com/once#top');
        $this->consume();

        $articles = $this->em()->getRepository(Article::class)->findBy(['sourceUrl' => 'https://example.com/once']);
        self::assertCount(1, $articles);
        self::assertCount(2, $this->em()->getRepository(LlmJob::class)->findBy(['step' => 'import_file', 'status' => LlmJobStatus::Done, 'user' => $this->author]));
    }

    public function testTheMediumExportUrlAndTheFeedGuidOfOnePostAreOneArticle(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->import("# Hooks\nx\n", 'https://medium.com/@me/php-hooks-1a2b3c4d5e6f');
        $this->import("# Hooks\nx\n", 'https://medium.com/p/1a2b3c4d5e6f');
        $this->import("# Hooks\nx\n", 'https://medium.com/@me/php-hooks-1a2b3c4d5e6f?source=rss-abc');
        $this->consume();

        self::assertCount(1, $this->em()->getRepository(Article::class)->findBy(['user' => $this->author, 'sourceUrl' => 'https://medium.com/p/1a2b3c4d5e6f']));
    }

    public function testTwoAuthorsMayImportTheSameUrl(): void
    {
        $client = static::createClient();
        $other = self::newAuthor();
        $this->logIn($client);
        $this->import("# Shared\nx\n", 'https://example.com/shared', $other);
        $this->import("# Shared\nx\n", 'https://example.com/shared');
        $this->consume();

        self::assertCount(2, $this->em()->getRepository(Article::class)->findBy(['sourceUrl' => 'https://example.com/shared']));
    }

    public function testSourceKeys(): void
    {
        self::assertSame('https://medium.com/p/1a2b3c4d5e6f', ImportArticleHandler::sourceKey('https://blog.medium.com/some-title-1a2b3c4d5e6f/'));
        self::assertSame('https://example.com/a-1a2b3c4d5e6f', ImportArticleHandler::sourceKey('https://example.com/a-1a2b3c4d5e6f#top'));
        self::assertSame('https://blog.example.com/?p=12', ImportArticleHandler::sourceKey('https://blog.example.com/?utm_source=rss&p=12&utm_medium=feed'));
        self::assertNull(ImportArticleHandler::sourceKey('javascript:alert(1)'));
        self::assertNull(ImportArticleHandler::sourceKey(''));
    }

    public function testAMediumExportZipBecomesLibraryArticles(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $tag = uniqid('z');
        $words = str_repeat('word ', 200);
        $this->uploadZip($client, [
            "medium-export/posts/2024-03-05_Hooks-$tag-1a2b3c4d5e6f.html" => self::mediumPost("Hooks $tag", 'Typed at last', 'https://medium.com/@me/hooks-1a2b3c4d5e6f', '2024-03-05T10:00:00.000Z',
                "<p class=\"graf graf--p\">Intro.</p><figure class=\"graf graf--figure\"><img src=\"https://cdn-images-1.medium.com/x.png\"><figcaption class=\"imageCaption\">A caption</figcaption></figure>"
                ."<h3 class=\"graf graf--h3\">Install</h3><p class=\"graf graf--p\">Run it.</p>"
                ."<pre class=\"graf graf--pre\"><span class=\"pre--content\">\$a = <strong class=\"markup--strong markup--pre-strong\">1</strong>;<br>echo <a href=\"https://php.net\" class=\"markup--anchor\">\$a</a>;</span></pre>"),
            "medium-export/posts/draft_Later-$tag-aaaaaaaaaaaa.html" => self::mediumPost("Draft $tag", '', 'https://medium.com/p/aaaaaaaaaaaa', '2024-01-01T00:00:00.000Z', "<p>$words</p>"),
            "medium-export/posts/2024-02-01_Thanks-bbbbbbbbbbbb.html" => self::mediumPost("Reply $tag", '', 'https://medium.com/@me/bbbbbbbbbbbb', '2024-02-01T00:00:00.000Z', '<p>Thanks, good point!</p>'),
            "__MACOSX/medium-export/posts/._2024-03-05_Hooks-$tag-1a2b3c4d5e6f.html" => 'binary junk',
            "medium-export/README.html" => '<p>not a post</p>',
        ]);
        self::assertResponseRedirects('/author', 303);
        $this->consume();

        // Hooks and the reply are queued; the draft, the __MACOSX copy and README.html are not.
        self::assertCount(2, $this->em()->getRepository(LlmJob::class)->findBy(['user' => $this->author, 'step' => 'import_file', 'status' => LlmJobStatus::Done]));
        self::assertSame([], $this->em()->getRepository(LlmJob::class)->findBy(['user' => $this->author, 'status' => LlmJobStatus::Failed]));
        $articles = $this->em()->getRepository(Article::class)->findBy(['user' => $this->author]);
        self::assertCount(1, $articles);
        $article = $articles[0];
        self::assertSame("Hooks $tag", $article->getTitle());
        self::assertSame('https://medium.com/p/1a2b3c4d5e6f', $article->getSourceUrl());
        self::assertSame('2024-03-05', $article->getPublishedAt()?->format('Y-m-d'));
        $file = self::getContainer()->get(ArticleFolder::class)->read($article->getFolder(), 'article.md');
        self::assertSame('Typed at last', $file['frontMatter']['subtitle']);
        self::assertMatchesRegularExpression('/^### Install$/m', $file['body']);
        self::assertStringNotContainsString('A caption', $file['body']);
        self::assertStringContainsString("```\n\$a = 1;\necho \$a;\n```", $file['body']);
        self::assertStringNotContainsString("Hooks $tag", $file['body']);
        self::assertStringNotContainsString('Typed at last', $file['body']);
    }

    public function testAMediumPostOnAPublicationDomainDedupesAgainstItsFeedGuid(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->import("# Hooks\nx\n", 'https://medium.com/p/1a2b3c4d5e6f');
        $this->uploadZip($client, ['posts/2024-03-05_Hooks-1a2b3c4d5e6f.html' => self::mediumPost('Hooks', '', 'https://levelup.example.com/hooks-1a2b3c4d5e6f', '2024-03-05T10:00:00.000Z', '<h3>Install</h3><p>Run it.</p>')]);
        $this->consume();

        self::assertCount(1, $this->em()->getRepository(Article::class)->findBy(['user' => $this->author]));
    }

    public function testACorruptZipIs422(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, 'not a zip at all');
        $client->request('POST', '/author/import', [], ['files' => [new UploadedFile($path, 'medium-export.zip', 'application/zip', null, true)]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.import-error', 'medium-export.zip is not a readable zip.');
    }

    public function testAFailedUploadIs422(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->request('POST', '/author/import', [], ['files' => [new UploadedFile('', 'medium-export.zip', 'application/zip', \UPLOAD_ERR_INI_SIZE, true)]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.import-error');
    }

    public function testAZipWithoutPostsIs422(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->uploadZip($client, ['notes/todo.txt' => 'x', 'posts/draft_Later-aaaaaaaaaaaa.html' => 'x']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.import-error', 'No posts found');
    }

    private static function mediumPost(string $title, string $subtitle, string $canonical, string $date, string $body): string
    {
        $sub = '' === $subtitle ? '' : "<h4 class=\"graf graf--h4 graf--subtitle\">$subtitle</h4>";

        return <<<HTML
            <!DOCTYPE html><html><head><title>$title</title></head><body><article class="h-entry">
            <header><h1 class="p-name">$title</h1></header>
            <section data-field="body" class="e-content"><section class="section"><div class="section-content"><div class="section-inner">
            <h3 class="graf graf--h3 graf--title">$title</h3>$sub$body
            </div></div></section></section>
            <footer><p>By <a class="p-author h-card" href="https://medium.com/@me">Me</a> on <a href="$canonical"><time class="dt-published" datetime="$date">a date</time></a>.</p>
            <p><a href="$canonical" class="p-canonical">Canonical link</a></p></footer>
            </article></body></html>
            HTML;
    }

    /** @param array<string, string> $entries */
    private function uploadZip(KernelBrowser $client, array $entries): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $client->request('POST', '/author/import', [], ['files' => [new UploadedFile($path, 'medium-export.zip', 'application/zip', null, true)]]);
    }

    private function import(string $content, string $sourceUrl, ?User $user = null): void
    {
        self::getContainer()->get(LlmJobs::class)->start(null, 'import_file', 'io', static fn (int $id) => new ImportArticle($id, 'post.html', $content, $sourceUrl), $user ?? $this->author);
    }

    private function article(string $title): Article
    {
        return $this->em()->getRepository(Article::class)->findOneBy(['title' => $title]) ?? throw new \LogicException("No $title.");
    }

    /**
     * @param array<string, string> $files
     * @param array<string, int> $modified */
    private function upload(KernelBrowser $client, array $files, array $modified = []): void
    {
        $uploads = [];
        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'imp');
            file_put_contents($path, $content);
            $uploads[] = new UploadedFile($path, $name, 'text/markdown', null, true);
        }
        $client->request('POST', '/author/import', ['modified' => json_encode($modified)], ['files' => $uploads]);
    }

    private function consume(): void
    {
        $transport = self::getContainer()->get('messenger.transport.io');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $worker = new Worker(['io' => $transport], self::getContainer()->get('messenger.bus.default'), $dispatcher);
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $e) use ($worker): void {
            if ($e->isWorkerIdle()) {
                $worker->stop();
            }
        });
        $worker->run(['sleep' => 0]);
        $this->em()->clear();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
