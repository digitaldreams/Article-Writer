<?php

namespace App\Shared\Llm;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Event\ResultConvertedEvent;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** Moves the LlmJob of a message through working, done and failed. Other messages are ignored. */
final class TrackLlmJob
{
    /** @var array{int, int}|null [input, output] tokens of the model calls made by the job the worker is running; null until one reports usage */
    private ?array $usage = null;

    public function __construct(private readonly ManagerRegistry $doctrine, private readonly LlmCost $cost)
    {
    }

    #[AsEventListener]
    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->usage = null;
        $this->update($event->getEnvelope(), static fn (LlmJob $job) => $job->markWorking());
    }

    /** Every model call; one agent call can make several, so they add up. */
    #[AsEventListener]
    public function onModelResult(ResultConvertedEvent $event): void
    {
        $result = $event->getResult();
        $tokens = $result->getMetadata()->get('token_usage');
        if ($tokens instanceof TokenUsageInterface) {
            [$in, $out] = [$tokens->getPromptTokens(), $tokens->getCompletionTokens()];
        } else {
            // The image model's converter has no token extractor; OpenAI still sends usage in the body.
            $usage = $result->getRawResult()?->getData()['usage'] ?? null;
            [$in, $out] = [$usage['input_tokens'] ?? null, $usage['output_tokens'] ?? null];
        }
        if (null === $in && null === $out) {
            return;
        }
        // ponytail: cached input tokens are priced as normal input, so OpenAI cost can read a bit high.
        $this->usage = [($this->usage[0] ?? 0) + (int) $in, ($this->usage[1] ?? 0) + (int) $out];
    }

    #[AsEventListener]
    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->update($event->getEnvelope(), function (LlmJob $job): void {
            $this->recordUsage($job);
            $job->markDone();
        });
    }

    #[AsEventListener]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $error = $event->getThrowable();
        if ($error instanceof HandlerFailedException) {
            $error = array_values($error->getWrappedExceptions())[0] ?? $error;
        }

        $this->update($event->getEnvelope(), function (LlmJob $job) use ($error): void {
            $this->recordUsage($job);
            $job->markFailed($error->getMessage());
        });
    }

    private function recordUsage(LlmJob $job): void
    {
        if (null === $this->usage) {
            return;
        }
        [$in, $out] = $this->usage;
        $job->recordUsage($in, $out, $this->cost->of($job->getProvider(), $job->getModel(), $in, $out));
        $this->usage = null;
    }

    /** @param callable(LlmJob): void $change */
    private function update(Envelope $envelope, callable $change): void
    {
        $message = $envelope->getMessage();
        if (!$message instanceof LlmJobMessage) {
            return;
        }

        // A failed handler can leave the entity manager closed; the worker is long-lived.
        $em = $this->doctrine->getManager();
        if ($em instanceof EntityManagerInterface && !$em->isOpen()) {
            $em = $this->doctrine->resetManager();
        }

        $job = $em->find(LlmJob::class, $message->llmJobId());
        if (!$job instanceof LlmJob) {
            return;
        }

        $change($job);
        $em->flush();
    }
}
