<?php

namespace App\Tests\Author\CrossCheckProfile;

use App\Author\CrossCheckProfile\CrossCheckAnswer;
use App\Author\CrossCheckProfile\CrossCheckPoint;
use App\Author\CrossCheckProfile\CrossCheckProfile;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobs;
use App\Shared\User\User;
use App\Tests\LogsInAuthor;
use App\Tests\RenderingPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class CrossCheckProfileTest extends WebTestCase
{
    use LogsInAuthor;

    private const BUTTON = 'Cross Check my Structure and Voice';
    private const PANEL = '/author/profile/cross-check';

    public function testThePanelHasNoButtonWhenOneFieldIsBlankOrOnlySpaces(): void
    {
        $client = static::createClient();
        $this->logIn($client);

        $client->request('GET', self::PANEL);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::BUTTON, (string) $client->getResponse()->getContent());

        $this->fillProfile(['samples' => '']);
        $client->request('GET', self::PANEL);
        self::assertStringNotContainsString(self::BUTTON, (string) $client->getResponse()->getContent());

        $this->fillProfile(['tone' => "  \n "]);
        $client->request('GET', self::PANEL);
        self::assertStringNotContainsString(self::BUTTON, (string) $client->getResponse()->getContent());
    }

    public function testThePanelShowsTheButtonWhenAllTenFieldsHaveValues(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fillProfile();

        $client->request('GET', self::PANEL);

        self::assertStringContainsString(self::BUTTON, (string) $client->getResponse()->getContent());
    }

    public function testTheProfilePageHasTheFrameAndATargetUnderEachLabel(): void
    {
        $client = static::createClient();
        $this->logIn($client);

        $crawler = $client->request('GET', '/author/profile');

        self::assertCount(1, $crawler->filter('turbo-frame#cross-check[src="'.self::PANEL.'"]'));
        foreach ([...User::WRITING_STRUCTURE, ...User::WRITING_VOICE] as $field) {
            self::assertCount(1, $crawler->filter("label + #cross-check-$field"), $field);
        }
    }

    public function testPostStartsAPrimaryJobOnThePrimaryQueue(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fillProfile();

        $client->request('POST', self::PANEL);

        self::assertResponseRedirects(self::PANEL, 303);
        $jobs = $this->em()->getRepository(LlmJob::class)->findBy(['step' => 'cross_check_profile']);
        self::assertCount(1, $jobs);
        self::assertSame('primary', $jobs[0]->getRole());
        self::assertCount(1, $this->transport('llm_primary')->getSent());
        self::assertInstanceOf(CrossCheckProfile::class, $this->transport('llm_primary')->getSent()[0]->getMessage());
        self::assertCount(0, $this->transport('llm_secondary')->getSent());
    }

    public function testPostWithAnIncompleteProfileIsAConflictAndStartsNoJob(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fillProfile(['devices' => ' ']);

        $client->request('POST', self::PANEL);

        self::assertResponseStatusCodeSame(409);
        self::assertCount(0, $this->em()->getRepository(LlmJob::class)->findBy(['step' => 'cross_check_profile']));
    }

    public function testPostWhileACheckIsQueuedIsAConflictAndStartsNoSecondJob(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fillProfile();

        $client->request('POST', self::PANEL);
        $client->request('POST', self::PANEL);

        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, $this->em()->getRepository(LlmJob::class)->findBy(['step' => 'cross_check_profile']));
    }

    public function testTheHandlerSendsAllTenValuesStoresThePointsAndLeavesTheProfileAlone(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $profile = $this->fillProfile();
        $sent = null;
        RenderingPlatform::install(self::getContainer(), 'ollama', static function ($model, $input) use (&$sent) {
            $sent = (string) $input->getUserMessage()?->asText();

            return new ObjectResult(new CrossCheckAnswer([new CrossCheckPoint('tone', 'Too formal.', 'Write as you talk.')]));
        });

        $this->runCheck();

        foreach ([...array_keys($profile['structure']), ...array_keys($profile['voice'])] as $field) {
            self::assertStringContainsString("value of $field", (string) $sent);
        }
        $author = $this->em()->find(User::class, $this->author->getId()) ?? throw new \LogicException('No author.');
        self::assertSame([['field' => 'tone', 'issue' => 'Too formal.', 'suggestion' => 'Write as you talk.']], $author->getWritingProfileCheck());
        self::assertSame($profile, $author->getWritingProfile());
    }

    public function testThePanelStreamsEachPointToItsFieldLabeledAsAiSuggestion(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fillProfile();
        $this->fakeAnswer(new CrossCheckPoint('tone', 'Too <b>formal</b>.', 'Write as you talk.'));
        $this->runCheck();

        $crawler = $client->request('GET', self::PANEL);

        $stream = $crawler->filter('turbo-stream[action="update"][target="cross-check-tone"]');
        self::assertCount(1, $stream);
        self::assertStringContainsString('AI suggestion', $stream->html());
        self::assertStringContainsString('Too &lt;b&gt;formal&lt;/b&gt;.', $stream->html());
        self::assertStringContainsString('Write as you talk.', $stream->html());
    }

    public function testAFieldWithoutPointsGetsAnEmptyStreamAndAnUnknownFieldIsAGeneralPoint(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fillProfile();
        $this->fakeAnswer(new CrossCheckPoint('tone', 'Too formal.', 'Write as you talk.'), new CrossCheckPoint('voice"><script>', 'Mixed types.', 'Pick one type.'));
        $this->runCheck();

        $crawler = $client->request('GET', self::PANEL);

        self::assertCount(10, $crawler->filter('turbo-stream'));
        self::assertStringNotContainsString('AI suggestion', $crawler->filter('turbo-stream[target="cross-check-closing"]')->html());
        self::assertStringNotContainsString('<script>', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Mixed types.', $crawler->filter('.ai-suggestions-general')->text());
    }

    public function testAnEmptyAnswerShowsNoIssuesFound(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fillProfile();
        $this->fakeAnswer();
        $this->runCheck();

        $client->request('GET', self::PANEL);

        self::assertStringContainsString('No issues found', (string) $client->getResponse()->getContent());
    }

    public function testASecondCheckReplacesTheOldPoints(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fillProfile();
        $answers = [
            new CrossCheckAnswer([new CrossCheckPoint('tone', 'Old issue.', 'Old fix.')]),
            new CrossCheckAnswer([new CrossCheckPoint('closing', 'New issue.', 'New fix.')]),
        ];
        RenderingPlatform::install(self::getContainer(), 'ollama', static function () use (&$answers) {
            return new ObjectResult(array_shift($answers));
        });
        $this->runCheck();
        $this->runCheck();

        $client->request('GET', self::PANEL);

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('New issue.', $content);
        self::assertStringNotContainsString('Old issue.', $content);
    }

    public function testAnotherAuthorsResultAndJobDoNotShow(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $other = self::newAuthor();
        $other->setWritingProfileCheck([['field' => 'tone', 'issue' => 'Secret issue.', 'suggestion' => 'Secret fix.']]);
        $job = self::getContainer()->get(LlmJobs::class)->start(null, 'cross_check_profile', 'primary', static fn (int $id) => new CrossCheckProfile($id), $other);
        $this->em()->flush();

        $client->request('GET', self::PANEL);

        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Secret issue.', $content);
        self::assertStringNotContainsString('job-'.$job->getId(), $content);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array{structure: array<string, string>, voice: array<string, string>}
     */
    private function fillProfile(array $overrides = []): array
    {
        $structure = [];
        foreach (User::WRITING_STRUCTURE as $field) {
            $structure[$field] = $overrides[$field] ?? "value of $field";
        }
        $voice = [];
        foreach (User::WRITING_VOICE as $field) {
            $voice[$field] = $overrides[$field] ?? "value of $field";
        }
        $author = $this->em()->find(User::class, $this->author->getId()) ?? throw new \LogicException('No author.');
        $author->setWritingProfile($structure, $voice);
        $this->em()->flush();

        return ['structure' => $structure, 'voice' => $voice];
    }

    private function fakeAnswer(CrossCheckPoint ...$points): void
    {
        RenderingPlatform::install(self::getContainer(), 'ollama', static fn () => new ObjectResult(new CrossCheckAnswer(array_values($points))));
    }

    private function runCheck(): void
    {
        $author = $this->em()->find(User::class, $this->author->getId()) ?? throw new \LogicException('No author.');
        self::getContainer()->get(LlmJobs::class)->start(null, 'cross_check_profile', 'primary', static fn (int $id) => new CrossCheckProfile($id), $author);
        $this->em()->flush();

        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $worker = new Worker(['llm_primary' => $this->transport('llm_primary')], self::getContainer()->get('messenger.bus.default'), $dispatcher);
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $e) use ($worker): void {
            if ($e->isWorkerIdle()) {
                $worker->stop();
            }
        });
        $worker->run(['sleep' => 0]);
        $this->em()->clear();
    }

    private function transport(string $name): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.'.$name);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
