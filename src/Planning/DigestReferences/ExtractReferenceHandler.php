<?php

namespace App\Planning\DigestReferences;

use App\Planning\Reference;
use App\Planning\ReferenceType;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Smalot\PdfParser\Parser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Fetches a link or reads an uploaded doc (images go to DescribeImage), saves the text, then queues its digest. */
#[AsMessageHandler(fromTransport: 'io')]
final readonly class ExtractReferenceHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private ArticleFolder $articleFolder,
        private LlmJobs $jobs,
    ) {
    }

    public function __invoke(ExtractReference $message): void
    {
        $reference = $this->em->find(Reference::class, $message->referenceId) ?? throw new \RuntimeException('The reference is gone.');

        $text = trim(match ($reference->getType()) {
            ReferenceType::Link => $this->pageText($this->httpClient->request('GET', $reference->getLocation())->getContent()),
            ReferenceType::Doc => $this->docText($reference),
            ReferenceType::Image => throw new \LogicException('Images are described by DescribeImage.'),
        });
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

    private function docText(Reference $reference): string
    {
        $raw = $this->articleFolder->readRaw($reference->getArticle()->getFolder(), $reference->getLocation());

        return str_ends_with(strtolower($reference->getLocation()), '.pdf') ? (new Parser())->parseContent($raw)->getText() : $raw;
    }
}
