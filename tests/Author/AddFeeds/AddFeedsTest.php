<?php

namespace App\Tests\Author\AddFeeds;

use App\Author\AddFeeds\Feed;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\Storage\ArticleFolder;
use App\Shared\User\User;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class AddFeedsTest extends WebTestCase
{
    use LogsInAuthor;

    private KernelBrowser $client;

    /** @var array<string, array{string, int}> feed URL => body, HTTP code */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // A reboot would drop the MockHttpClient, and the next refresh would hit the real feed.
        $this->client->disableReboot();
        $this->logIn($this->client);
        self::getContainer()->set('http_client', new MockHttpClient(fn (string $_, string $url) => new MockResponse(...[
            $this->responses[$url][0] ?? throw new \LogicException("No response for $url."),
            ['http_code' => $this->responses[$url][1]],
        ])));
    }

    public function testRssItemsAreImportedAndMediumResponsesSkipped(): void
    {
        $feed = $this->addFeed('https://medium.com/feed/@me', self::rss());

        $this->refresh($feed);

        self::assertSame(['Hooks in PHP 8.4'], $this->titles());
        $article = $this->em()->getRepository(Article::class)->findOneBy(['user' => $this->author]) ?? throw new \LogicException('No article.');
        self::assertSame('https://medium.com/p/1a2b3c4d5e6f', $article->getSourceUrl());
        self::assertSame('2024-03-05', $article->getPublishedAt()?->format('Y-m-d'));
        self::assertNotNull($this->em()->find(Feed::class, $feed->getId())?->getLastRefreshedAt());
    }

    public function testAtomEntriesAreImported(): void
    {
        $feed = $this->addFeed('https://blog.example.com/atom.xml', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom"><title>Blog</title>
              <entry><title>Enums</title><link rel="alternate" href="https://blog.example.com/enums?utm_source=atom"/><id>tag:blog,1</id>
                <published>2023-11-02T08:00:00Z</published><content type="html">&lt;h2&gt;Why&lt;/h2&gt;&lt;p&gt;Because.&lt;/p&gt;</content></entry>
            </feed>
            XML);

        $this->refresh($feed);

        $article = $this->em()->getRepository(Article::class)->findOneBy(['user' => $this->author]) ?? throw new \LogicException('No article.');
        self::assertSame('Enums', $article->getTitle());
        self::assertSame('https://blog.example.com/enums', $article->getSourceUrl());
        self::assertSame('2023-11-02', $article->getPublishedAt()?->format('Y-m-d'));
    }

    public function testASummaryOnlyFeedImportsTheSummaryAndXhtmlAtomContentWins(): void
    {
        $feed = $this->addFeed('https://blog.example.com/feed', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Blog</title>
              <item><title>Short one</title><guid isPermaLink="true">https://blog.example.com/short</guid><description>Just a summary.</description></item>
            </channel></rss>
            XML);
        $this->refresh($feed);
        $atom = $this->addFeed('https://other.example.com/atom.xml', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom"><title>Other</title>
              <entry><title>Xhtml</title><link href="/xhtml"/><summary>The summary.</summary>
                <content type="xhtml"><div xmlns="http://www.w3.org/1999/xhtml"><p>The full body.</p></div></content></entry>
            </feed>
            XML);
        $this->refresh($atom);

        self::assertSame(['Short one', 'Xhtml'], $this->titles());
        $articles = $this->em()->getRepository(Article::class)->findBy(['user' => $this->author], ['id' => 'ASC']);
        self::assertSame('https://blog.example.com/short', $articles[0]->getSourceUrl());
        self::assertSame('https://other.example.com/xhtml', $articles[1]->getSourceUrl());
        $folder = self::getContainer()->get(ArticleFolder::class);
        self::assertStringContainsString('Just a summary.', $folder->read($articles[0]->getFolder(), 'article.md')['body']);
        self::assertStringContainsString('The full body.', $folder->read($articles[1]->getFolder(), 'article.md')['body']);
    }

    public function testAnHttpErrorFailsTheJob(): void
    {
        $feed = $this->addFeed('https://medium.com/feed/@gone', 'Not found', 404);

        $this->refresh($feed);

        $crawler = $this->client->request('GET', '/author/feeds');
        self::assertSelectorExists('.feed .job-failed');
        self::assertCount(1, $crawler->filter('.feed')->selectButton('Retry'));
    }

    public function testDeleteWhileAJobIsOpenIs409(): void
    {
        $feed = $this->addFeed('https://medium.com/feed/@me', self::rss());
        $this->client->request('POST', '/author/feeds/'.$feed->getId().'/refresh');

        $this->client->request('POST', '/author/feeds/'.$feed->getId().'/delete');

        self::assertResponseStatusCodeSame(409);
    }

    public function testASecondRefreshImportsNothing(): void
    {
        $feed = $this->addFeed('https://medium.com/feed/@me', self::rss());
        $this->refresh($feed);
        $jobs = \count($this->em()->getRepository(LlmJob::class)->findBy(['user' => $this->author, 'step' => 'import_file']));

        $this->refresh($feed);

        self::assertSame(['Hooks in PHP 8.4'], $this->titles());
        // Only the response is queued again: it never becomes an article, so the feed cannot tell it was seen.
        self::assertCount($jobs + 1, $this->em()->getRepository(LlmJob::class)->findBy(['user' => $this->author, 'step' => 'import_file']));
    }

    public function testAMediumSourceLinkIsDedupedAgainstTheExport(): void
    {
        $this->em()->persist(Article::imported($this->author, 'hooks', 'Hooks from the export', 'https://medium.com/p/1a2b3c4d5e6f'));
        $this->em()->flush();
        $feed = $this->addFeed('https://medium.com/feed/@me', str_replace('<guid isPermaLink="false">https://medium.com/p/1a2b3c4d5e6f</guid>', '', self::rss()));

        $this->refresh($feed);

        self::assertSame(['Hooks from the export'], $this->titles());
    }

    public function testANonFeedBodyFailsTheJobWithAHint(): void
    {
        $feed = $this->addFeed('https://medium.com/@me', '<!DOCTYPE html><html><body><p>Profile</p></body></html>');

        $this->refresh($feed);

        $job = $this->em()->getRepository(LlmJob::class)->findOneBy(['user' => $this->author, 'step' => 'fetch_feed']);
        self::assertSame(LlmJobStatus::Failed, $job?->getStatus());
        $crawler = $this->client->request('GET', '/author/feeds');
        self::assertStringContainsString('Not a feed. Medium feeds look like https://medium.com/feed/@you', $crawler->filter('.feed .job-error')->text());
    }

    public function testAnInvalidOrRepeatedUrlIs422(): void
    {
        foreach (['not a url', 'ftp://example.com/feed'] as $url) {
            $this->client->request('GET', '/author/feeds');
            $this->client->submitForm('Add feed', ['add_feed[url]' => $url]);
            self::assertResponseStatusCodeSame(422);
        }

        $this->client->request('GET', '/author/feeds');
        $this->client->submitForm('Add feed', ['add_feed[url]' => 'https://example.com/feed']);
        self::assertResponseRedirects('/author/feeds', 303);
        $this->client->request('GET', '/author/feeds');
        $this->client->submitForm('Add feed', ['add_feed[url]' => 'https://example.com/feed']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'This feed is already added.');
    }

    public function testRefreshWhileAJobIsOpenIs409(): void
    {
        $feed = $this->addFeed('https://medium.com/feed/@me', self::rss());
        $this->client->request('POST', '/author/feeds/'.$feed->getId().'/refresh');
        self::assertResponseRedirects('/author/feeds', 303);

        $this->client->request('POST', '/author/feeds/'.$feed->getId().'/refresh');

        self::assertResponseStatusCodeSame(409);
    }

    public function testAnotherAuthorsFeedIs404(): void
    {
        $theirs = new Feed(self::newAuthor(), 'https://example.com/feed');
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->em()->clear();

        $this->client->request('POST', '/author/feeds/'.$theirs->getId().'/refresh');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/author/feeds/'.$theirs->getId().'/delete');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteKeepsTheImportedArticles(): void
    {
        $feed = $this->addFeed('https://medium.com/feed/@me', self::rss());
        $this->refresh($feed);

        $this->client->request('POST', '/author/feeds/'.$feed->getId().'/delete');

        self::assertResponseRedirects('/author/feeds', 303);
        self::assertNull($this->em()->find(Feed::class, $feed->getId()));
        self::assertSame(['Hooks in PHP 8.4'], $this->titles());
    }

    private static function rss(): string
    {
        $post = '<h3>Hooks in PHP 8.4</h3><p>Intro.</p><h3>Install</h3><p>Run it.</p>';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>Me on Medium</title>
              <item><title>Hooks in PHP 8.4</title><link>https://medium.com/@me/hooks-in-php-8-4-1a2b3c4d5e6f?source=rss-abc------2</link>
                <guid isPermaLink="false">https://medium.com/p/1a2b3c4d5e6f</guid><pubDate>Tue, 05 Mar 2024 10:00:00 GMT</pubDate>
                <content:encoded><![CDATA[$post]]></content:encoded></item>
              <item><title>Great point, thanks!</title><link>https://medium.com/@me/bbbbbbbbbbbb?source=rss-abc------2</link>
                <guid isPermaLink="false">https://medium.com/p/bbbbbbbbbbbb</guid><pubDate>Wed, 06 Mar 2024 10:00:00 GMT</pubDate>
                <content:encoded><![CDATA[<p>Great point, thanks!</p>]]></content:encoded></item>
            </channel></rss>
            XML;
    }

    private function addFeed(string $url, string $body, int $code = 200): Feed
    {
        $this->responses[$url] = [$body, $code];
        // A refresh clears the entity manager: load the author again.
        $feed = new Feed($this->em()->find(User::class, $this->author->getId()) ?? throw new \LogicException('No author.'), $url);
        $this->em()->persist($feed);
        $this->em()->flush();

        return $feed;
    }

    private function refresh(Feed $feed): void
    {
        $this->client->request('POST', '/author/feeds/'.$feed->getId().'/refresh');
        self::assertResponseRedirects('/author/feeds', 303);

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

    /** @return list<string> */
    private function titles(): array
    {
        return array_map(static fn (Article $a) => $a->getTitle(), $this->em()->getRepository(Article::class)->findBy(['user' => $this->author], ['id' => 'ASC']));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
