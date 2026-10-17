<?php

namespace App\Tests;

use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\EventListener\TemplateRendererListener;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** InMemoryPlatform skips the platform's InvocationEvent; this runs the real template listener first, so a fake sees the prompt the model would. */
final readonly class RenderingPlatform implements PlatformInterface
{
    public function __construct(private InMemoryPlatform $inner, private TemplateRendererListener $templates)
    {
    }

    /** Fakes the platform service "ai.platform.<name>"; $answer is what InMemoryPlatform takes (a result, a string or a closure). */
    public static function install(ContainerInterface $container, string $name, \Closure|string $answer): void
    {
        $container->set('ai.platform.'.$name, new self(new InMemoryPlatform($answer), $container->get('ai.platform.template_renderer_listener')));
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $event = new InvocationEvent($model instanceof Model ? $model : $this->inner->getModelCatalog()->getModel($model), $input, $options);
        ($this->templates)($event);

        return $this->inner->invoke($model, $event->getInput(), $event->getOptions());
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }
}
