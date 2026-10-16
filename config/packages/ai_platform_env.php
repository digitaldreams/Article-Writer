<?php

// An agent's "platform" is a service id, resolved when the container is built, so %env()% cannot be used.
// Read AI_{PRIMARY,SECONDARY,TITLE,POSTER}_PLATFORM (ollama|openai|anthropic|gemini) here instead; changing it needs a cache:clear.
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    foreach (['primary', 'secondary', 'title', 'poster'] as $role) {
        $key = 'AI_'.strtoupper($role).'_PLATFORM';
        $platform = 'ai.platform.'.($_SERVER[$key] ?? $_ENV[$key] ?? 'ollama');
        $container->parameters()->set('app.ai.'.$role.'_platform', $platform);
        // Same platform as a service, for code that invokes it without an agent (the poster image model).
        $container->services()->alias('app.ai.'.$role.'_platform', $platform);
    }
};
