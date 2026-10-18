<?php

namespace App\Shared\Llm;

use Symfony\AI\Platform\Bridge\Ollama\Ollama;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Same runtime options on every Ollama call, so switching steps never reloads the model and the prompt cache survives.
 * Only Ollama models get them; OpenAI rejects unknown body fields. Options passed by the caller win.
 */
final class OllamaOptions
{
    public const OPTIONS = [
        'num_ctx' => 16384,
        'num_thread' => 6,
        'keep_alive' => -1,
    ];

    #[AsEventListener]
    public function __invoke(InvocationEvent $event): void
    {
        if ($event->getModel() instanceof Ollama) {
            $event->setOptions($event->getOptions() + self::OPTIONS);
        }
    }
}
