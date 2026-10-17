<?php

namespace App\Tests\Planning;

use App\Planning\PlanInput;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

/** Builds a plan directly in a given state and runs the LLM workers against a fake primary model. */
abstract class PlanningTestCase extends WebTestCase
{
    use LogsInAuthor;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->logIn($this->client);
    }

    /** Walks the state machine to $state, so the article is valid for that step. */
    protected function planIn(ArticleState $state): Article
    {
        $article = Article::plan($this->author, 'plan', 'PHP 8 features');
        foreach ([ArticleState::Questions, ArticleState::Flows, ArticleState::Outline, ArticleState::Finalize, ArticleState::Approved, ArticleState::Written] as $next) {
            if ($article->getState() === $state) {
                break;
            }
            $article->moveTo($next);
        }
        $this->em()->persist($article);
        $this->em()->persist(new PlanInput($article, 'PHP 8 features', 'PHP developers', 'intermediate', 'Typed properties, hooks'));
        $this->em()->flush();

        return $article;
    }

    /** @param \Closure(mixed $input, array<string, mixed> $options): ResultInterface $answer */
    protected function fakePrimary(\Closure $answer): void
    {
        self::getContainer()->set('ai.platform.ollama', new InMemoryPlatform(static fn ($model, $input, array $options) => $answer($input, $options)));
    }

    protected function consume(): void
    {
        $transports = [];
        foreach (['llm_primary', 'llm_secondary', 'io'] as $name) {
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
    }

    protected function reload(Article $article): Article
    {
        $this->em()->clear();

        return $this->em()->find(Article::class, $article->getId()) ?? throw new \LogicException('The article is gone.');
    }

    protected function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
