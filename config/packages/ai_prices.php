<?php

// USD per 1M tokens, by platform (as in AI_*_PLATFORM) and model (as in AI_*_MODEL).
// A job on a model missing here still counts its tokens, but shows no cost. Local ollama models are free.
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('app.ai.prices', [
        'openai' => [
            'gpt-6-luna' => ['in' => 0.10, 'out' => 0.50],
            'gpt-6-sol' => ['in' => 2.0, 'out' => 10.0],
            'gpt-image-2' => ['in' => 5.0, 'out' => 30.0],
            'gpt-image-2.5-flare' => ['in' => 5.0, 'out' => 30.0],
            'gpt-image-2.5-sunburst' => ['in' => 5.0, 'out' => 30.0],

        ],
    ]);
};
