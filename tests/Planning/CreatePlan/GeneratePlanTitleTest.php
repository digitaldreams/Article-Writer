<?php

namespace App\Tests\Planning\CreatePlan;

use App\Planning\CreatePlan\PlanTitleAnswer;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

final class GeneratePlanTitleTest extends WebTestCase
{
    use LogsInAuthor;

    public function testGeneratedTitleReplacesTheTopicOnThePlansList(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fakeModel(static fn () => new ObjectResult(new PlanTitleAnswer('Agents Without Fear')));
        $this->createPlan($client, 'my rough topic');

        $this->consume();
        $client->request('GET', '/plans');

        self::assertSelectorTextContains('body', 'Agents Without Fear');
        self::assertSelectorTextNotContains('body', 'my rough topic');
    }

    public function testFailedTitleJobLeavesTheTopic(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $this->fakeModel(static fn () => throw new \RuntimeException('model down'));
        $this->createPlan($client, 'my rough topic');

        $this->consume();
        $client->request('GET', '/plans');

        self::assertSelectorTextContains('body', 'my rough topic');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(LlmJob::class)->findBy(['step' => 'generate_plan_title', 'status' => LlmJobStatus::Failed]));
    }

    public function testPromptReachesTheModelWithoutHtmlEscaping(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $seen = '';
        $this->fakeModel(static function ($model, $input) use (&$seen) {
            $seen = (string) $input->getUserMessage()?->asText();

            return new ObjectResult(new PlanTitleAnswer('t'));
        });
        $this->createPlan($client, "Tom & Jerry's <T> ->");

        $this->consume();

        self::assertStringContainsString("Topic: Tom & Jerry's <T> ->", $seen);
    }

    private function fakeModel(callable $answer): void
    {
        self::getContainer()->set('ai.platform.openai', new InMemoryPlatform($answer));
    }

    private function createPlan(KernelBrowser $client, string $topic): void
    {
        $client->disableReboot();
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Create')->form(['create_plan[topic]' => $topic]));
    }

    private function consume(): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $worker = new Worker(['llm_secondary' => self::getContainer()->get('messenger.transport.llm_secondary')], self::getContainer()->get('messenger.bus.default'), $dispatcher);
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $e) use ($worker): void {
            if ($e->isWorkerIdle()) {
                $worker->stop();
            }
        });
        $worker->run(['sleep' => 0]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
