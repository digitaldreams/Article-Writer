<?php

namespace App\Tests;

use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\EventListener\TemplateRendererListener;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Runs the real template listener first, so a fake sees the prompt the model would, and gets it as the MessageBag (a mock platform's closure gets the normalized payload). */
final readonly class RenderingPlatform implements PlatformInterface
{
    public function __construct(private \Closure|string $answer, private TemplateRendererListener $templates)
    {
    }

    /** Fakes the platform service "ai.platform.<name>"; $answer is a result, a string or a closure ($model, $input, $options). */
    public static function install(ContainerInterface $container, string $name, \Closure|string $answer): void
    {
        $container->set('ai.platform.'.$name, new self($answer, $container->get('ai.platform.template_renderer_listener')));
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $event = new InvocationEvent($model instanceof Model ? $model : $this->getModelCatalog()->getModel($model), $input, $options);
        ($this->templates)($event);
        $answer = $this->answer instanceof \Closure
            ? fn (Model $model) => ($this->answer)($model, $event->getInput(), $event->getOptions())
            : $this->answer;

        return MockPlatformFactory::createPlatform($answer)->invoke($model, $event->getInput(), $event->getOptions());
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return new FallbackModelCatalog();
    }
}
