<?php

namespace App\Tests\Planning\DigestReferences;

use App\Planning\CreatePlan\PlanTitleAnswer;
use App\Planning\DigestReferences\DigestReferenceHandler;
use App\Planning\DigestReferences\SettleReferences;
use App\Planning\Reference;
use App\Planning\ReferencesSettled;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

final class DigestReferencesTest extends WebTestCase
{
    /** @var list<int> pending references counted at each ReferencesSettled */
    private array $settled = [];

    public function testThreeReferencesFireReferencesSettledOnceAfterTheLastOne(): void
    {
        $client = $this->client();
        $this->fakeWeb();
        $this->fakeModel();
        $this->createPlan($client, "https://example.com/a\nhttps://example.com/b", ['notes.md' => '# Notes about hooks']);

        $this->consume();

        self::assertSame([0], $this->settled);
        $references = $this->references();
        self::assertSame([ReferenceStatus::Done, ReferenceStatus::Done, ReferenceStatus::Done], array_map(static fn (Reference $r) => $r->getStatus(), $references));
        self::assertSame('digest of: Property hooks page a', $references[0]->getDigest());
        self::assertSame('digest of: # Notes about hooks', $references[2]->getDigest());
    }

    public function testBadUrlBecomesUnreadableAndThePlanStillSettles(): void
    {
        $client = $this->client();
        $this->fakeWeb();
        $this->fakeModel();
        $this->createPlan($client, "https://example.com/a\nhttps://example.com/missing");

        $this->consume();

        self::assertSame([ReferenceStatus::Done, ReferenceStatus::Unreadable], array_map(static fn (Reference $r) => $r->getStatus(), $this->references()));
        self::assertSame([0], $this->settled);
    }

    public function testTwoReferencesFinishingTogetherSettleOnce(): void
    {
        $client = $this->client();
        $this->fakeWeb();
        $this->createPlan($client, "https://example.com/a\nhttps://example.com/b");
        $this->consume(['io']);

        $settle = self::getContainer()->get(SettleReferences::class);
        [$a, $b] = $this->references();
        $settle->finish((int) $a->getId(), 'digest a');
        $settle->finish((int) $b->getId(), 'digest b');

        self::assertSame([0], $this->settled);
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

    public function testAnImageReferenceIsDescribedThenDigested(): void
    {
        $client = $this->client();
        $sent = null;
        $this->fakeModel(describe: static function ($input) use (&$sent) {
            $sent = $input->getUserMessage()?->getContent()[0] ?? null;

            return new TextResult('A diagram of a property hook');
        });
        $this->createPlan($client, '', images: ['hook.png']);

        $this->consume(['llm_secondary']);

        $reference = $this->references()[0];
        self::assertInstanceOf(Image::class, $sent);
        self::assertSame('A diagram of a property hook', $reference->getExtractedText());
        self::assertSame('digest of: A diagram of a property hook', $reference->getDigest());
        self::assertSame('test-vision', $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'describe_image'])?->getModel());
        self::assertSame([0], $this->settled);
    }

    public function testAFailedImageDescriptionMakesTheReferenceUnreadable(): void
    {
        $client = $this->client();
        $this->fakeModel(describe: static fn () => throw new \RuntimeException('The model is down.'));
        $this->createPlan($client, '', images: ['hook.png']);

        $this->consume(['llm_secondary']);

        self::assertSame(ReferenceStatus::Unreadable, $this->references()[0]->getStatus());
        self::assertSame([0], $this->settled);
    }

    public function testQuestionsPageShowsAStatusChipPerReference(): void
    {
        $client = $this->client();
        $this->fakeWeb();
        $this->fakeModel();
        $this->createPlan($client, "https://example.com/a\nhttps://example.com/missing");
        $this->consume();

        $article = $this->em()->getRepository(Article::class)->findOneBy([]) ?? throw new \LogicException();
        $client->request('GET', '/plans/'.$article->getId().'/questions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.references li:nth-child(1) .badge', 'Done');
        self::assertSelectorTextContains('.references li:nth-child(2) .badge', 'Unreadable');
        self::assertSelectorExists('.job-failed');
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(ReferencesSettled::class, function (ReferencesSettled $event): void {
            $this->settled[] = $this->em()->getRepository(Reference::class)->count(['article' => $event->articleId, 'status' => ReferenceStatus::Pending]);
        });

        return $client;
    }

    private function fakeWeb(): void
    {
        self::getContainer()->set('http_client', new MockHttpClient(static fn (string $_, string $url) => str_ends_with($url, '/missing')
            ? new MockResponse('not here', ['http_code' => 404])
            : new MockResponse('<html><body><nav>Menu</nav><main>Property hooks page '.substr($url, -1).'<script>track()</script></main></body></html>')));
    }

    /**
     * The secondary and the vision agent share this platform; the vision agent is told apart by its model.
     *
     * @param ?\Closure(): void                   $onDigest
     * @param ?\Closure(mixed): ResultInterface $describe
     */
    private function fakeModel(?\Closure $onDigest = null, ?\Closure $describe = null): void
    {
        self::getContainer()->set('ai.platform.openai', new InMemoryPlatform(
            static function (Model $model, $input, array $options) use ($onDigest, $describe) {
                if (PlanTitleAnswer::class === ($options['response_format'] ?? null)) {
                    return new ObjectResult(new PlanTitleAnswer('A title'));
                }
                if ('test-vision' === $model->getName()) {
                    return ($describe ?? throw new \LogicException('No image expected.'))($input);
                }
                $onDigest?->__invoke();

                return new TextResult('digest of: '.substr((string) $input->getUserMessage()?->asText(), 0, 40));
            },
        ));
    }

    /**
     * @param array<string, string> $docs
     * @param list<string>          $images
     */
    private function createPlan(KernelBrowser $client, string $links, array $docs = [], array $images = []): void
    {
        $crawler = $client->request('GET', '/plans/new');
        $form = $crawler->selectButton('Create')->form([
            'create_plan[topic]' => 'PHP 8 features '.uniqid(),
            'create_plan[links]' => $links,
        ]);
        $i = 0;
        foreach ($docs as $name => $content) {
            $path = sys_get_temp_dir().'/'.$name;
            file_put_contents($path, $content);
            $form['create_plan[docs]'][$i++]->upload($path);
        }
        foreach ($images as $i => $name) {
            $path = sys_get_temp_dir().'/'.$name;
            // 1x1 transparent PNG
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
            $form['create_plan[images]'][$i]->upload($path);
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
