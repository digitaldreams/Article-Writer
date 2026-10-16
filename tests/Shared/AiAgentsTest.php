<?php

namespace App\Tests\Shared;

use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AiAgentsTest extends KernelTestCase
{
    public function testPrimaryAndSecondaryAgentsAnswerThroughTheirPlatformAndModelFromEnv(): void
    {
        $container = self::getContainer();
        foreach (['ollama', 'openai'] as $platform) {
            $container->set('ai.platform.'.$platform, new InMemoryPlatform(
                static fn ($model) => $platform.':'.(\is_string($model) ? $model : $model->getName()),
            ));
        }

        self::assertSame('ollama:test-primary', $container->get('ai.agent.primary')->call('hi')->getContent());
        self::assertSame('openai:test-secondary', $container->get('ai.agent.secondary')->call('hi')->getContent());
    }
}
