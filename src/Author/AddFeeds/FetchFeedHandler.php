<?php

namespace App\Author\AddFeeds;

use App\Author\ImportArticle;
use App\Author\ImportArticleHandler;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Reads an RSS 2.0 or Atom feed and queues one import per item not in the library yet. */
#[AsMessageHandler(fromTransport: 'io')]
final readonly class FetchFeedHandler
{
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    public function __construct(
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private LlmJobs $jobs,
    ) {
    }

    public function __invoke(FetchFeed $message): void
    {
        $feed = $this->em->find(Feed::class, $message->feedId) ?? throw new \RuntimeException('The feed is gone.');
        // External entities are off in PHP 8; NONET keeps libxml off the network too.
        $xml = @simplexml_load_string($this->httpClient->request('GET', $feed->getUrl())->getContent(), options: LIBXML_NONET);
        if (false === $xml || !\in_array($xml->getName(), ['rss', 'feed'], true)) {
            throw new \RuntimeException('Not a feed. Medium feeds look like https://medium.com/feed/@you');
        }

        $user = $feed->getUser();
        $this->em->wrapInTransaction(function () use ($xml, $feed, $user): void {
            foreach ('rss' === $xml->getName() ? $this->rssItems($xml) : $this->atomEntries($xml) as [$title, $link, $html, $date]) {
                // A relative Atom link points into the feed's site.
                $link = str_starts_with($link, '/') && !str_starts_with($link, '//') ? self::origin($feed->getUrl()).$link : $link;
                $key = ImportArticleHandler::sourceKey($link);
                // ponytail: no http link → no dedupe key, it would come back on every refresh; skipped.
                // Workers have no owner filter: scope the lookup by the feed's author.
                if (null === $key || null !== $this->em->getRepository(Article::class)->findOneBy(['user' => $user, 'sourceUrl' => $key])) {
                    continue;
                }
                $this->jobs->start(null, 'import_file', 'io', static fn (int $id) => new ImportArticle($id, $title, $html, $link, $date, 'html'), $user);
            }
            $feed->refreshed();
        });
    }

    /** @return iterable<array{string, string, string, ?\DateTimeImmutable}> */
    private function rssItems(\SimpleXMLElement $rss): iterable
    {
        foreach ($rss->channel->item as $item) {
            $guid = trim((string) $item->guid);
            // Medium's guid (medium.com/p/<id>) stays the same when the post sits on a publication domain.
            $link = trim((string) $item->link);
            if (1 === preg_match('~^https://medium\.com/p/[0-9a-f]{12}$~', $guid) || ('' === $link && 'false' !== (string) $item->guid['isPermaLink'])) {
                $link = $guid;
            }
            $encoded = trim((string) $item->children(self::CONTENT_NS)->encoded);

            yield [trim((string) $item->title), $link, '' !== $encoded ? $encoded : (string) $item->description, self::date((string) $item->pubDate)];
        }
    }

    /** @return iterable<array{string, string, string, ?\DateTimeImmutable}> */
    private function atomEntries(\SimpleXMLElement $atom): iterable
    {
        foreach ($atom->entry as $entry) {
            $link = '';
            foreach ($entry->link as $candidate) {
                if (\in_array((string) $candidate['rel'], ['', 'alternate'], true)) {
                    $link = trim((string) $candidate['href']);
                    break;
                }
            }
            // type="xhtml" wraps the body in a <div>, not in text.
            $content = 'xhtml' === (string) $entry->content['type'] ? (string) $entry->content->children()->asXML() : trim((string) $entry->content);

            yield [
                trim((string) $entry->title),
                $link,
                '' !== $content ? $content : (string) $entry->summary,
                self::date((string) $entry->published) ?? self::date((string) $entry->updated),
            ];
        }
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        try {
            return '' === trim($value) ? null : new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
