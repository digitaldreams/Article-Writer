<?php

namespace App\Tests\Author\AnalyzeWriting;

use App\Shared\User\User;
use App\Tests\RenderingPlatform;
use App\Author\AnalyzeWriting\ExtractWritingNotes;
use App\Author\AnalyzeWriting\MergeWritingProfile;
use App\Author\AnalyzeWriting\SettleWritingProfile;
use App\Author\WritingNote;
use App\Author\AnalyzeWriting\WritingNotesAnswer;
use App\Author\AnalyzeWriting\WritingProfileAnswer;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use App\Shared\Llm\LlmJobStatus;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class AnalyzeWritingTest extends WebTestCase
{
    use LogsInAuthor;

    public function testEveryNoteFinishedRunsOneMergeThatWritesStructureAndVoice(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fakeModel();
        $this->startNotes(['A' => "# A\nx\n", 'B' => "# B\ny\n"]);

        $this->consume(['io', 'llm_secondary']);

        self::assertCount(2, $this->em()->getRepository(WritingNote::class)->findAll());
        self::assertCount(1, $this->mergeMessages());
        $profile = $this->em()->find(User::class, $this->author->getId());
        self::assertSame('a story', $profile?->getWritingProfile()['structure']['openingType'] ?? null);
        self::assertSame('friendly', $profile->getWritingProfile()['voice']['tone']);
        self::assertSame("One.\n\nTwo.", $profile->getWritingProfile()['voice']['samples']);
        self::assertSame([], $this->jobsOf('merge_writing_profile', LlmJobStatus::Failed));
    }

    public function testMergeRunsOnceWhenTheLastTwoNotesFinishTogether(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fakeModel();
        $this->startNotes(['A' => "# A\nx\n", 'B' => "# B\ny\n"]);

        foreach ($this->jobsOf('extract_writing_notes') as $job) {
            $this->em()->persist(new WritingNote($job->getArticle() ?? throw new \LogicException(), 'a story', 5, 'by feature', true, 'summary', 'friendly', 'short sentences', 'questions', 'snippet first', ['A sample.']));
            $job->markDone();
        }
        $this->em()->flush();

        $settle = self::getContainer()->get(SettleWritingProfile::class);
        $settle->settle((int) $this->author->getId());
        $settle->settle((int) $this->author->getId());

        self::assertCount(1, $this->mergeMessages());
        self::assertCount(1, $this->jobsOf('merge_writing_profile'));
    }

    public function testMergeWaitsWhileANoteIsStillPending(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->startNotes(['A' => "# A\nx\n", 'B' => "# B\ny\n"]);
        $job = $this->jobsOf('extract_writing_notes')[0];
        $this->em()->persist(new WritingNote($job->getArticle() ?? throw new \LogicException(), 'a story', 5, 'by feature', true, 'summary', 'friendly', 'short sentences', 'questions', 'snippet first', ['A sample.']));
        $job->markDone();
        $this->em()->flush();

        self::getContainer()->get(SettleWritingProfile::class)->settle((int) $this->author->getId());

        self::assertCount(0, $this->mergeMessages());
    }

    public function testAFailedNoteDoesNotBlockTheMerge(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->startNotes(['A' => "# A\nx\n", 'B' => "# B\ny\n"]);
        [$ok, $failed] = $this->jobsOf('extract_writing_notes');
        $this->em()->persist(new WritingNote($ok->getArticle() ?? throw new \LogicException(), 'a story', 5, 'by feature', true, 'summary', 'friendly', 'short sentences', 'questions', 'snippet first', ['A sample.']));
        $ok->markDone();
        $failed->markFailed('model down');
        $this->em()->flush();

        self::getContainer()->get(SettleWritingProfile::class)->settle((int) $this->author->getId());

        self::assertCount(1, $this->mergeMessages());
    }

    public function testAFailedNoteJobRunsTheCheckThroughTheWorkerListener(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fakeModel(failNotesFor: 'B');
        $this->startNotes(['A' => "# A\nx\n", 'B' => "# B\ny\n"]);

        $this->consume(['io', 'llm_secondary']);

        self::assertCount(1, $this->jobsOf('extract_writing_notes', LlmJobStatus::Failed));
        self::assertCount(1, $this->jobsOf('merge_writing_profile', LlmJobStatus::Done));
    }

    public function testTheButtonQueuesOneNotesJobPerUnanalyzedArticle(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        [$done] = $this->library(['A', 'B', 'C']);
        $this->em()->persist(new WritingNote($done, 'a story', 5, 'by feature', true, 'summary', 'friendly', 'short sentences', 'questions', 'snippet first', ['A sample.']));
        $this->em()->flush();

        $client->request('POST', '/author/profile/analyze');

        self::assertResponseRedirects('/author/profile', 303);
        self::assertEqualsCanonicalizing(['B', 'C'], array_map(static fn (LlmJob $j) => $j->getArticle()?->getTitle(), $this->jobsOf('extract_writing_notes')));
        self::assertSame([], $this->jobsOf('merge_writing_profile'));
        $crawler = $client->request('GET', '/author/profile');
        self::assertSelectorTextContains('.analyze-count', '1 analyzed / 3 in library');
        self::assertCount(2, $crawler->filter('.structure-job'));
    }

    public function testAnalyzingAFullyAnalyzedLibraryMergesAtOnce(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        [$done] = $this->library(['A']);
        $this->em()->persist(new WritingNote($done, 'a story', 5, 'by feature', true, 'summary', 'friendly', 'short sentences', 'questions', 'snippet first', ['A sample.']));
        $this->em()->flush();

        $client->request('POST', '/author/profile/analyze');

        self::assertSame([], $this->jobsOf('extract_writing_notes'));
        self::assertCount(1, $this->jobsOf('merge_writing_profile'));
    }

    public function testARetryOfAnOldNotesJobAddsNoSecondNote(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fakeModel();
        [$article] = $this->library(['A']);
        $this->em()->persist(new WritingNote($article, 'a story', 5, 'by feature', true, 'summary', 'friendly', 'short sentences', 'questions', 'snippet first', ['A sample.']));
        $this->em()->flush();
        self::getContainer()->get(LlmJobs::class)->start($article, 'extract_writing_notes', 'secondary', static fn (int $id) => new ExtractWritingNotes($id, (int) $article->getId()));

        $this->consume(['llm_secondary']);

        self::assertCount(1, $this->em()->getRepository(WritingNote::class)->findBy(['user' => $this->author]));
    }

    public function testAnEmptyLibraryIs409(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->em()->persist(Article::plan($this->author, 'plan', 'Only a plan'));
        $this->em()->flush();

        $client->request('POST', '/author/profile/analyze');

        self::assertResponseStatusCodeSame(409);
    }

    public function testASecondClickWhileRunningIs409(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->library(['A']);
        $client->request('POST', '/author/profile/analyze');

        $client->request('POST', '/author/profile/analyze');

        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, $this->jobsOf('extract_writing_notes'));
    }

    public function testTheButtonRunsNotesThenOneMerge(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fakeModel();
        $this->library(['A', 'B']);

        $client->request('POST', '/author/profile/analyze');
        $this->consume(['llm_secondary']);

        self::assertCount(2, $this->jobsOf('extract_writing_notes', LlmJobStatus::Done));
        self::assertCount(1, $this->jobsOf('merge_writing_profile', LlmJobStatus::Done));
    }

    public function testAnotherAuthorsAnalysisJobIs404(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $theirs = Article::imported(self::newAuthor(), 'theirs', 'Theirs');
        $this->em()->persist($theirs);
        $this->em()->flush();
        $job = self::getContainer()->get(LlmJobs::class)->start($theirs, 'extract_writing_notes', 'secondary', static fn (int $id) => new ExtractWritingNotes($id, (int) $theirs->getId()));
        $this->em()->clear();

        $client->request('GET', '/jobs/'.$job->getId().'/card');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param list<string> $titles
     *
     * @return list<Article>
     */
    private function library(array $titles): array
    {
        $folder = self::getContainer()->get(ArticleFolder::class);
        $articles = [];
        foreach ($titles as $title) {
            $article = Article::imported($this->author, strtolower($title), $title);
            $this->em()->persist($article);
            $this->em()->flush();
            $folder->write($article->getFolder(), 'article.md', ['title' => $title], "# $title\nx\n");
            $articles[] = $article;
        }

        return $articles;
    }

    private function fakeModel(?string $failNotesFor = null): void
    {
        RenderingPlatform::install(self::getContainer(), 'openai', 
            static function ($model, $input, array $options) use ($failNotesFor) {
                if (WritingProfileAnswer::class === $options['response_format']) {
                    return new ObjectResult(new WritingProfileAnswer('a story', 'by feature', '5', 'yes', 'summary', 'friendly', 'short sentences', 'questions', 'snippet first', ['One.', 'Two.']));
                }
                if (null !== $failNotesFor && str_contains((string) $input->getUserMessage()?->asText(), "# $failNotesFor")) {
                    throw new \RuntimeException('model down');
                }

                return new ObjectResult(new WritingNotesAnswer('a story', 5, 'by feature', true, 'summary', 'friendly', 'short sentences', 'questions', 'snippet first', ['A sample.']));
            },
        );
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
            static fn ($m) => $m instanceof MergeWritingProfile,
        ));
    }

    /** @param array<string, string> $bodies title => article.md body */
    private function startNotes(array $bodies): void
    {
        $folder = self::getContainer()->get(ArticleFolder::class);
        $jobs = self::getContainer()->get(LlmJobs::class);
        foreach ($bodies as $title => $body) {
            $article = Article::imported($this->author, strtolower($title), $title);
            $this->em()->persist($article);
            $this->em()->flush();
            $folder->write($article->getFolder(), 'article.md', ['title' => $title], $body);
            $jobs->start($article, 'extract_writing_notes', 'secondary', static fn (int $id) => new ExtractWritingNotes($id, (int) $article->getId()));
        }
        $this->em()->flush();
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
