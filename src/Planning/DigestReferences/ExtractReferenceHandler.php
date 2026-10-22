<?php

namespace App\Planning\DigestReferences;

use App\Planning\Reference;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Fetches a link, saves the page text, then queues its digest. */
#[AsMessageHandler(fromTransport: 'io')]
final readonly class ExtractReferenceHandler
{
    public const MAX_BYTES = 5_000_000;
    // ponytail: fixed cap so one huge page can't turn into dozens of digest calls; raise it with a bigger model context.
    public const MAX_TEXT_CHARS = 60_000;

    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire(service: 'app.reference_http_client')]
        private HttpClientInterface $httpClient,
        private LlmJobs $jobs,
    ) {
    }

    public function __invoke(ExtractReference $message): void
    {
        $reference = $this->em->find(Reference::class, $message->referenceId) ?? throw new \RuntimeException('The reference is gone.');

        try {
            $html = $this->httpClient->request('GET', $reference->getLocation(), [
                'max_duration' => 30,
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new \RuntimeException('The page is larger than 5 MB.');
                    }
                },
            ])->getContent();
        } catch (TransportExceptionInterface $e) {
            // A blocked address or an oversized page fails the same way every time. No previous exception:
            // LlmRetryStrategy would find the transport error in the chain and retry. The card keeps Retry.
            throw new \RuntimeException($e->getMessage());
        }
        $text = mb_substr(trim($this->pageText($html)), 0, self::MAX_TEXT_CHARS);
        if ('' === $text) {
            throw new \RuntimeException('The reference has no readable text.');
        }

        $this->em->wrapInTransaction(function () use ($reference, $text): void {
            $reference->saveExtractedText($text);
            $referenceId = (int) $reference->getId();
            $this->jobs->start($reference->getArticle(), 'digest_reference', 'secondary', static fn (int $id) => new DigestReference($id, $referenceId));
        });
    }

    private function pageText(string $html): string
    {
        $crawler = new Crawler($html);
        $crawler->filter('script, style, noscript, nav, header, footer')->each(static function (Crawler $node): void {
            $element = $node->getNode(0);
            $element?->parentNode?->removeChild($element);
        });
        $content = $crawler->filter('main, article');

        return ($content->count() > 0 ? $content : $crawler->filter('body'))->first()->text('');
    }
}
