<?php

namespace App\Tests\Setup\ImportArticles;

use App\Setup\ImportArticles\MergeStructureProfile;
use App\Setup\ImportArticles\SettleStructureProfile;
use App\Setup\ImportArticles\StructureNote;
use App\Setup\ImportArticles\StructureNotesAnswer;
use App\Setup\ImportArticles\StructureProfileAnswer;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\WriterProfile\WriterProfile;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class StructureProfileTest extends WebTestCase
{
    use LogsInAuthor;

    public function testEveryImportedFileGetsNotesAndOneMergeWritesTheProfileAfterTheLastNote(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fakeModel();
        $this->upload($client, ['a.md' => "# A\nx\n", 'b.md' => "# B\ny\n"]);

        $this->consume(['io', 'llm_secondary']);

        self::assertCount(2, $this->em()->getRepository(StructureNote::class)->findAll());
        self::assertCount(1, $this->mergeMessages());
        $profile = $this->em()->getRepository(WriterProfile::class)->findOneBy([]);
        self::assertSame('a story', $profile?->getStructureProfile()['openingType'] ?? null);
        self::assertSame([], $this->jobsOf('merge_structure_profile', LlmJobStatus::Failed));
    }

    public function testMergeRunsOnceWhenTheLastTwoNotesFinishTogether(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fakeModel();
        $this->upload($client, ['a.md' => "# A\nx\n", 'b.md' => "# B\ny\n"]);
        $this->consume(['io']);

        foreach ($this->jobsOf('extract_structure_notes') as $job) {
            $this->em()->persist(new StructureNote($job->getArticle() ?? throw new \LogicException(), 'a story', 5, 'by feature', true, 'summary'));
            $job->markDone();
        }
        $this->em()->flush();

        $settle = self::getContainer()->get(SettleStructureProfile::class);
        $settle->settle((int) $this->author->getId());
        $settle->settle((int) $this->author->getId());

        self::assertCount(1, $this->mergeMessages());
        self::assertCount(1, $this->jobsOf('merge_structure_profile'));
    }

    public function testMergeWaitsWhileANoteIsStillPending(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->upload($client, ['a.md' => "# A\nx\n", 'b.md' => "# B\ny\n"]);
        $this->consume(['io']);
        $job = $this->jobsOf('extract_structure_notes')[0];
        $this->em()->persist(new StructureNote($job->getArticle() ?? throw new \LogicException(), 'a story', 5, 'by feature', true, 'summary'));
        $job->markDone();
        $this->em()->flush();

        self::getContainer()->get(SettleStructureProfile::class)->settle((int) $this->author->getId());

        self::assertCount(0, $this->mergeMessages());
    }

    public function testAFailedNoteDoesNotBlockTheMerge(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->upload($client, ['a.md' => "# A\nx\n", 'b.md' => "# B\ny\n"]);
        $this->consume(['io']);
        [$ok, $failed] = $this->jobsOf('extract_structure_notes');
        $this->em()->persist(new StructureNote($ok->getArticle() ?? throw new \LogicException(), 'a story', 5, 'by feature', true, 'summary'));
        $ok->markDone();
        $failed->markFailed('model down');
        $this->em()->flush();

        self::getContainer()->get(SettleStructureProfile::class)->settle((int) $this->author->getId());

        self::assertCount(1, $this->mergeMessages());
    }

    public function testAFailedImportFileDoesNotBlockTheMerge(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->upload($client, ['a.md' => "# A\nx\n", 'empty.md' => "  \n"]);
        $this->consume(['io']);
        $job = $this->jobsOf('extract_structure_notes')[0];
        $this->em()->persist(new StructureNote($job->getArticle() ?? throw new \LogicException(), 'a story', 5, 'by feature', true, 'summary'));
        $job->markDone();
        $this->em()->flush();

        self::getContainer()->get(SettleStructureProfile::class)->settle((int) $this->author->getId());

        self::assertCount(1, $this->jobsOf('import_file', LlmJobStatus::Failed));
        self::assertCount(1, $this->mergeMessages());
    }

    public function testAFailedNoteJobRunsTheCheckThroughTheWorkerListener(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fakeModel(failNotesFor: 'B');
        $this->upload($client, ['a.md' => "# A\nx\n", 'b.md' => "# B\ny\n"]);

        $this->consume(['io', 'llm_secondary']);

        self::assertCount(1, $this->jobsOf('extract_structure_notes', LlmJobStatus::Failed));
        self::assertCount(1, $this->jobsOf('merge_structure_profile', LlmJobStatus::Done));
    }

    private function fakeModel(?string $failNotesFor = null): void
    {
        self::getContainer()->set('ai.platform.openai', new InMemoryPlatform(
            static function ($model, $input, array $options) use ($failNotesFor) {
                if (StructureProfileAnswer::class === $options['response_format']) {
                    return new ObjectResult(new StructureProfileAnswer('a story', 'by feature', '5', 'yes', 'summary'));
                }
                if (null !== $failNotesFor && str_contains((string) $input->getUserMessage()?->asText(), "# $failNotesFor")) {
                    throw new \RuntimeException('model down');
                }

                return new ObjectResult(new StructureNotesAnswer('a story', 5, 'by feature', true, 'summary'));
            },
        ));
    }

    /** @return list<LlmJob> */
    private function jobsOf(string $step, ?LlmJobStatus $status = null): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(LlmJob::class)->findBy(['step' => $step] + (null === $status ? [] : ['status' => $status]), ['id' => 'ASC']);
    }

    /** @return list<object> */
    private function mergeMessages(): array
    {
        $transport = self::getContainer()->get('messenger.transport.llm_secondary');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $transport->getSent()),
            static fn ($m) => $m instanceof MergeStructureProfile,
        ));
    }

    /** @param array<string, string> $files */
    private function upload(KernelBrowser $client, array $files): void
    {
        $uploads = [];
        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'imp');
            file_put_contents($path, $content);
            $uploads[] = new UploadedFile($path, $name, 'text/markdown', null, true);
        }
        $client->request('POST', '/setup/import', ['modified' => '{}'], ['files' => $uploads]);
    }

    /** @param list<string> $names */
    private function consume(array $names): void
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
