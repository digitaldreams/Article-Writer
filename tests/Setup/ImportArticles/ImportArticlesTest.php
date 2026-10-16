<?php

namespace App\Tests\Setup\ImportArticles;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
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
    public function testThreeUploadedFilesBecomeThreeFoldersAndImportedArticles(): void
    {
        $client = static::createClient();
        $tag = uniqid('t');
        $this->upload($client, [
            'a.md' => "---\ntitle: First $tag\ndate: 2024-03-05\n---\nBody one\n",
            'b.md' => "# Second $tag\n\nNo front matter\n",
            "c$tag.md" => "Just text\n",
        ]);

        self::assertResponseRedirects('/setup', 303);
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
        $tag = uniqid('d');
        $this->upload($client, ["one.md" => "# Same $tag\nx\n", "two.md" => "# Same $tag\ny\n"]);
        $this->consume();

        $folders = array_map(static fn (Article $a) => $a->getFolder(), $this->em()->getRepository(Article::class)->findBy(['title' => "Same $tag"]));
        self::assertCount(2, array_unique($folders));
    }

    public function testFailedFileIsShownByNameWithRetry(): void
    {
        $client = static::createClient();
        $this->upload($client, ['empty-one.md' => "  \n"]);
        $this->consume();

        $crawler = $client->request('GET', '/setup');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('empty-one.md', $crawler->filter('.import-file')->text());
        self::assertStringContainsString('The file is empty.', $crawler->filter('.job-error')->text());
        self::assertCount(1, $crawler->selectButton('Retry'));
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
        $client->request('POST', '/setup/import', ['modified' => json_encode($modified)], ['files' => $uploads]);
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
