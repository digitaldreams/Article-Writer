<?php

namespace App\Tests\Shared;

use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AiAgentsTest extends KernelTestCase
{
    public function testStepAgentsAnswerThroughTheirRolesPlatformAndModelFromEnv(): void
    {
        $container = self::getContainer();
        foreach (['ollama', 'openai'] as $platform) {
            $container->set('ai.platform.'.$platform, MockPlatformFactory::createPlatform(
                static fn ($model) => $platform.':'.$model->getName(),
            ));
        }

        self::assertSame('ollama:test-primary', $container->get('ai.agent.outline')->call('hi')->getContent());
        self::assertSame('openai:test-secondary', $container->get('ai.agent.digest')->call('hi')->getContent());
        self::assertSame('ollama:test-check', $container->get('ai.agent.final_check')->call('hi')->getContent());
    }

    public function testEveryStepPromptIsAnMdFileOnItsAgent(): void
    {
        $dir = self::getContainer()->getParameter('kernel.project_dir').'/prompts';
        foreach (['digest', 'writing_notes', 'writing_profile', 'questions', 'outline', 'article', 'rewrite', 'title', 'poster', 'share', 'final_check', 'refactor'] as $agent) {
            self::assertFileExists($dir.'/'.$agent.'_agent.md');
            self::assertTrue(self::getContainer()->has('ai.agent.'.$agent), $agent);
        }
    }
}
