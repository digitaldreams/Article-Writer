<?php

namespace App\Tests\Planning\AskQuestions;

use App\Planning\AskQuestions\AskedQuestion;
use App\Planning\AskQuestions\QuestionList;
use App\Planning\CreatePlan\PlanTitleAnswer;
use App\Planning\Question;
use App\Planning\QuestionRole;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

final class AskQuestionsTest extends WebTestCase
{
    use LogsInAuthor;

    public function testNextAfterBrainstormingMovesToQuestionsAndSavesTheTaggedQuestions(): void
    {
        $client = $this->client();
        $prompt = '';
        $this->fakeQuestions(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getSystemMessage()?->getContent();

            return new ObjectResult(new QuestionList([
                new AskedQuestion(QuestionRole::Interviewer, 'Which problem does it solve?'),
                new AskedQuestion(QuestionRole::Reader, 'Which hook works for you?', ['Less boilerplate', 'Safer types']),
            ]));
        });
        $this->createPlan($client);

        $this->consume();

        self::assertSame(ArticleState::Questions, $this->article()->getState());
        self::assertSame(
            [['interviewer', 'Which problem does it solve?', []], ['reader', 'Which hook works for you?', ['Less boilerplate', 'Safer types']]],
            array_map(static fn (Question $q) => [$q->getRole()->value, $q->getText(), $q->getOptions()], $this->questions()),
        );
        self::assertStringContainsString('Audience: PHP developers', $prompt);
    }

    public function testMoreThanFiveQuestionsFailTheJobWithRetry(): void
    {
        $client = $this->client();
        $this->fakeQuestions(static fn () => new ObjectResult(new QuestionList(array_fill(0, 6, new AskedQuestion(QuestionRole::Reader, 'Why?')))));
        $this->createPlan($client);

        $this->consume();

        self::assertSame([], $this->questions());
        self::assertStringContainsString('6 questions', (string) $this->askJob()->getError());
        $client->request('GET', '/plans/'.$this->article()->getId().'/questions');
        self::assertSelectorExists('.job-failed button');
    }

    public function testAnswerThatIsNotAQuestionListFailsTheJob(): void
    {
        $client = $this->client();
        $this->fakeQuestions(static fn () => new TextResult('{not json'));
        $this->createPlan($client);

        $this->consume();

        self::assertSame(LlmJobStatus::Failed, $this->askJob()->getStatus());
    }

    public function testReplayReplacesTheQuestions(): void
    {
        $client = $this->client();
        $round = 0;
        $this->fakeQuestions(static function () use (&$round) {
            ++$round;

            return new ObjectResult(new QuestionList([new AskedQuestion(QuestionRole::Interviewer, 'Round '.$round)]));
        });
        $this->createPlan($client);
        $this->consume();

        $bus = self::getContainer()->get('messenger.bus.default');
        $bus->dispatch($this->askJob()->storedMessage());
        $this->consume();

        self::assertSame(['Round 2'], array_map(static fn (Question $q) => $q->getText(), $this->questions()));
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->disableReboot();

        return $client;
    }

    /** @param \Closure(mixed): ResultInterface $answer */
    private function fakeQuestions(\Closure $answer): void
    {
        self::getContainer()->set('ai.platform.ollama', new InMemoryPlatform(static fn ($model, $input) => $answer($input)));
        self::getContainer()->set('ai.platform.openai', new InMemoryPlatform(static fn () => new ObjectResult(new PlanTitleAnswer('A title'))));
    }

    private function createPlan(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Create')->form([
            'create_plan[topic]' => 'PHP 8 features '.uniqid(),
        ]));
        $id = $this->article()->getId();
        $client->request('POST', '/plans/'.$id.'/brainstorm/notes', ['audience' => 'PHP developers', 'readerLevel' => '', 'roughFlow' => '']);
        $client->request('POST', '/plans/'.$id.'/brainstorm/next');
    }

    private function article(): Article
    {
        $this->em()->clear();

        return $this->em()->getRepository(Article::class)->findOneBy([]) ?? throw new \LogicException('No article.');
    }

    private function askJob(): LlmJob
    {
        return $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'ask_questions']) ?? throw new \LogicException('No ask job.');
    }

    /** @return list<Question> */
    private function questions(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(Question::class)->findBy([], ['position' => 'ASC']);
    }

    private function consume(): void
    {
        $transports = [];
        foreach (['llm_primary', 'llm_secondary'] as $name) {
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
