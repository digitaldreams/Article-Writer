<?php

namespace App\Tests\Shared\Llm;

use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Runs the real bridges on mocked HTTP: what counts as "cut off" is the provider's wording, mapped by the bridge. */
final class RejectCutOffAnswerTest extends KernelTestCase
{
    public function testAnOllamaAnswerStoppedByTheLengthLimitIsRejected(): void
    {
        $this->expectExceptionMessageIs('The model hit its length limit, so the answer is cut off.');

        $this->askOllama(['message' => ['content' => 'half an arti'], 'done_reason' => 'length']);
    }

    public function testAnOllamaAnswerThatStoppedOnItsOwnIsKept(): void
    {
        self::assertSame('whole article', $this->askOllama(['message' => ['content' => 'whole article'], 'done_reason' => 'stop']));
    }

    public function testAnOllamaAnswerWithoutAReasonIsKept(): void
    {
        self::assertSame('whole article', $this->askOllama(['message' => ['content' => 'whole article']]));
    }

    public function testAnOpenAiAnswerStoppedByTheLengthLimitIsRejectedEvenNextToReasoning(): void
    {
        $this->expectExceptionMessageIs('The model hit its length limit, so the answer is cut off.');

        $this->askOpenAi([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'thinking']]],
                ['type' => 'message', 'id' => 'msg_1', 'status' => 'incomplete', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'half an arti']]],
            ],
        ]);
    }

    public function testACompletedOpenAiAnswerIsKept(): void
    {
        self::assertSame('whole article', $this->askOpenAi([
            'status' => 'completed',
            'output' => [
                ['type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'whole article']]],
            ],
        ]));
    }

    /** @param array<string, mixed> $answer */
    private function askOllama(array $answer): string
    {
        $http = new MockHttpClient(static fn (string $_, string $url) => str_ends_with($url, '/api/show')
            ? new MockResponse('{"capabilities":["completion"]}')
            : new MockResponse(json_encode($answer, \JSON_THROW_ON_ERROR)));

        return $this->ask(OllamaFactory::createPlatform('http://ollama.test', httpClient: $http, eventDispatcher: $this->dispatcher()), 'gemma4');
    }

    /** @param array<string, mixed> $answer */
    private function askOpenAi(array $answer): string
    {
        $http = new MockHttpClient(new MockResponse(json_encode($answer, \JSON_THROW_ON_ERROR)));

        return $this->ask(OpenAiFactory::createPlatform('sk-test', $http, eventDispatcher: $this->dispatcher()), 'gpt-6-luna');
    }

    private function ask(PlatformInterface $platform, string $model): string
    {
        return $platform->invoke($model, new MessageBag(Message::ofUser('hi')))->asText();
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return self::getContainer()->get(EventDispatcherInterface::class);
    }
}
