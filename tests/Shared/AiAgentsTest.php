<?php

namespace App\Tests\Shared;

use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AiAgentsTest extends KernelTestCase
{
    public function testStepAgentsAnswerThroughTheirRolesPlatformAndModelFromEnv(): void
    {
        $container = self::getContainer();
        foreach (['ollama', 'openai'] as $platform) {
            $container->set('ai.platform.'.$platform, new InMemoryPlatform(
                static fn ($model) => $platform.':'.(\is_string($model) ? $model : $model->getName()),
            ));
        }

        self::assertSame('ollama:test-primary', $container->get('ai.agent.outline')->call('hi')->getContent());
        self::assertSame('openai:test-secondary', $container->get('ai.agent.digest')->call('hi')->getContent());
    }

    public function testEveryStepPromptIsAnMdFileOnItsAgent(): void
    {
        $dir = self::getContainer()->getParameter('kernel.project_dir').'/prompts';
        foreach (['plan_title', 'digest', 'structure_notes', 'structure_profile', 'questions', 'flows', 'outline', 'checkup', 'article', 'review', 'rewrite', 'title', 'poster', 'share'] as $agent) {
            self::assertFileExists($dir.'/'.$agent.'_agent.md');
            self::assertTrue(self::getContainer()->has('ai.agent.'.$agent), $agent);
        }
    }
}
