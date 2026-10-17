<?php

namespace App\Tests\Planning\DigestReferences;

use App\Tests\RenderingPlatform;
use App\Planning\CreatePlan\PlanTitleAnswer;
use App\Planning\DigestReferences\DigestReferenceHandler;
use App\Planning\Reference;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
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
        $this->createPlan($client, "https://example.com/a\nhttps://example.com/b", ['notes.md' => '# Notes about hooks']);

        $this->consume();

        $references = $this->references();
        self::assertSame([ReferenceStatus::Done, ReferenceStatus::Done, ReferenceStatus::Done], array_map(static fn (Reference $r) => $r->getStatus(), $references));
        self::assertSame('digest of: Property hooks page a', $references[0]->getDigest());
        self::assertSame('digest of: # Notes about hooks', $references[2]->getDigest());
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
        $this->createPlan($client, '', ['long.md' => str_repeat('a', DigestReferenceHandler::PART_CHARS).'b']);

        $this->consume();

        self::assertSame(3, $calls);
        self::assertStringStartsWith('digest of: digest of: aaa', (string) $this->references()[0]->getDigest());
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
        self::getContainer()->set('http_client', new MockHttpClient(static fn (string $_, string $url) => str_ends_with($url, '/missing')
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

    /** @param array<string, string> $docs */
    private function createPlan(KernelBrowser $client, string $links, array $docs = []): void
    {
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Create')->form(['create_plan[topic]' => 'PHP 8 features '.uniqid()]));
        $crawler = $client->followRedirect();
        $form = $crawler->selectButton('Add references')->form(['add_references[links]' => $links]);
        $i = 0;
        foreach ($docs as $name => $content) {
            $path = sys_get_temp_dir().'/'.$name;
            file_put_contents($path, $content);
            $form['add_references[docs]'][$i++]->upload($path);
        }
        $client->submit($form);
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
