<?php

namespace App\Tests\Planning\DigestReferences;

use App\Tests\RenderingPlatform;
use App\Planning\CreatePlan\PlanTitleAnswer;
use App\Planning\DigestReferences\DigestReferenceHandler;
use App\Planning\DigestReferences\ExtractReference;
use App\Planning\DigestReferences\ExtractReferenceHandler;
use App\Planning\Reference;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobs;
use App\Shared\Llm\LlmJobStatus;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

final class DigestReferencesTest extends WebTestCase
{
    use LogsInAuthor;

    public function testThreeReferencesAreEachDigested(): void
    {
        $client = $this->client();
        $this->fakeWeb();
        $this->fakeModel();
        $this->createPlan($client, "https://example.com/a\nhttps://example.com/b\nhttps://example.com/c");

        $this->consume();

        $references = $this->references();
        self::assertSame([ReferenceStatus::Done, ReferenceStatus::Done, ReferenceStatus::Done], array_map(static fn (Reference $r) => $r->getStatus(), $references));
        self::assertSame('digest of: Property hooks page a', $references[0]->getDigest());
        self::assertSame('digest of: Property hooks page c', $references[2]->getDigest());
    }

    public function testBadUrlBecomesUnreadable(): void
    {
        $client = $this->client();
        $this->fakeWeb();
        $this->fakeModel();
        $this->createPlan($client, "https://example.com/a\nhttps://example.com/missing");

        $this->consume();

        self::assertSame([ReferenceStatus::Done, ReferenceStatus::Unreadable], array_map(static fn (Reference $r) => $r->getStatus(), $this->references()));
    }

    public function testLongTextIsDigestedInPartsThenThePartDigestsAreMerged(): void
    {
        $client = $this->client();
        $calls = 0;
        $this->fakeModel(function () use (&$calls): void {
            ++$calls;
        });
        self::getContainer()->set('app.reference_http_client', new MockHttpClient(new MockResponse('<main>'.str_repeat('a', DigestReferenceHandler::PART_CHARS).'b</main>')));
        $this->createPlan($client, 'https://example.com/long');

        $this->consume();

        self::assertSame(3, $calls);
        self::assertStringStartsWith('digest of: digest of: aaa', (string) $this->references()[0]->getDigest());
    }

    /** The form refuses a raw IP, but a DNS name or a redirect can still point inside; the client checks the address it connects to. */
    public function testReferenceOnAPrivateAddressBecomesUnreadableWithoutBeingFetched(): void
    {
        $client = $this->client();
        $fetched = false;
        self::getContainer()->set('http_client', new MockHttpClient(static function () use (&$fetched): MockResponse {
            $fetched = true;

            return new MockResponse('<main>{"models":["secret"]}</main>');
        }));
        $this->fakeModel();
        $this->createPlan($client, '');
        $article = $this->em()->getRepository(Article::class)->findOneBy([]) ?? throw new \LogicException();
        $reference = new Reference($article, 'http://127.0.0.1:11434/api/tags');
        $this->em()->persist($reference);
        $this->em()->flush();
        $referenceId = (int) $reference->getId();
        self::getContainer()->get(LlmJobs::class)->start($article, 'extract_reference', 'io', static fn (int $id) => new ExtractReference($id, $referenceId));

        $this->consume();

        self::assertFalse($fetched);
        self::assertSame(ReferenceStatus::Unreadable, $this->em()->find(Reference::class, $referenceId)?->getStatus());
        self::assertStringContainsString('is blocked', (string) $this->failedJobError());
    }

    public function testPageOverTheSizeLimitBecomesUnreadable(): void
    {
        $client = $this->client();
        $this->fakeModel();
        self::getContainer()->set('app.reference_http_client', new MockHttpClient(new MockResponse('<main>'.str_repeat('a', ExtractReferenceHandler::MAX_BYTES).'</main>')));
        $this->createPlan($client, 'https://example.com/huge');

        $this->consume();

        self::assertSame([ReferenceStatus::Unreadable], array_map(static fn (Reference $r) => $r->getStatus(), $this->references()));
        self::assertStringContainsString('larger than 5 MB', (string) $this->failedJobError());
    }

    public function testLongPageTextIsCutBeforeItIsDigested(): void
    {
        $client = $this->client();
        $this->fakeModel();
        self::getContainer()->set('app.reference_http_client', new MockHttpClient(new MockResponse('<main>'.str_repeat('a', ExtractReferenceHandler::MAX_TEXT_CHARS + 500).'</main>')));
        $this->createPlan($client, 'https://example.com/long');

        $this->consume();

        self::assertSame(ExtractReferenceHandler::MAX_TEXT_CHARS, mb_strlen((string) $this->references()[0]->getExtractedText()));
    }

    public function testBrainstormPageShowsAStatusChipPerReference(): void
    {
        $client = $this->client();
        $this->fakeWeb();
        $this->fakeModel();
        $this->createPlan($client, "https://example.com/a\nhttps://example.com/missing");
        $this->consume();

        $article = $this->em()->getRepository(Article::class)->findOneBy([]) ?? throw new \LogicException();
        $client->request('GET', '/plans/'.$article->getId().'/brainstorm');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.references li:nth-child(1) .badge', 'Done');
        self::assertSelectorTextContains('.references li:nth-child(2) .badge', 'Unreadable');
        self::assertSelectorExists('.job-failed');
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->disableReboot();

        return $client;
    }

    private function fakeWeb(): void
    {
        self::getContainer()->set('app.reference_http_client', new MockHttpClient(static fn (string $_, string $url) => str_ends_with($url, '/missing')
            ? new MockResponse('not here', ['http_code' => 404])
            : new MockResponse('<html><body><nav>Menu</nav><main>Property hooks page '.substr($url, -1).'<script>track()</script></main></body></html>')));
    }

    /** @param ?\Closure(): void $onDigest */
    private function fakeModel(?\Closure $onDigest = null): void
    {
        RenderingPlatform::install(self::getContainer(), 'openai',
            static function (Model $model, $input, array $options) use ($onDigest) {
                if (PlanTitleAnswer::class === ($options['response_format'] ?? null)) {
                    return new ObjectResult(new PlanTitleAnswer('A title'));
                }
                $onDigest?->__invoke();

                // The request starts with a label line ("The text of a reference:"), then the text.
                return new TextResult('digest of: '.substr(explode("\n\n", (string) $input->getUserMessage()?->asText(), 2)[1], 0, 40));
            },
        );
    }

    private function createPlan(KernelBrowser $client, string $links): void
    {
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Start brainstorming')->form(['create_plan[topic]' => 'PHP 8 features '.uniqid()]));
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Add references')->form(['add_references[links]' => $links]));
    }

    private function failedJobError(): ?string
    {
        return $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'extract_reference', 'status' => LlmJobStatus::Failed])?->getError();
    }

    /** @return list<Reference> */
    private function references(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(Reference::class)->findBy([], ['id' => 'ASC']);
    }

    /** @param list<string> $names */
    private function consume(array $names = ['io', 'llm_secondary']): void
    {
        $transports = [];
        foreach ($names as $name) {
            $transports[$name] = self::getContainer()->get('messenger.transport.'.$name);
        }
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $worker = new Worker($transports, self::getContainer()->get('messenger.bus.default'), $dispatcher);
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
