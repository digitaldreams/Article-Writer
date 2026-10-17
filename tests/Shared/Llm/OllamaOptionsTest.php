<?php

namespace App\Tests\Shared\Llm;

use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OllamaOptionsTest extends KernelTestCase
{
    public function testEveryOllamaCallSendsTheSameContextThreadsAndKeepAlive(): void
    {
        $chat = $this->ollamaChatBody([]);

        self::assertSame(['num_ctx' => 16384, 'num_thread' => 6], $chat['options']);
        self::assertSame(-1, $chat['keep_alive']);
    }

    public function testOptionsPassedByTheCallerWinAndStillReachOllama(): void
    {
        $chat = $this->ollamaChatBody(['num_ctx' => 4096, 'temperature' => 0.2, 'think' => false]);

        self::assertSame(['num_ctx' => 4096, 'temperature' => 0.2, 'num_thread' => 6], $chat['options']);
        self::assertFalse($chat['think']);
    }

    public function testOpenAiCallsGetNoOllamaOptions(): void
    {
        $body = null;
        $http = new MockHttpClient(static function (string $_, string $__, array $request) use (&$body) {
            $body = json_decode($request['body'], true);

            return new MockResponse('{}');
        });
        $platform = OpenAiFactory::createPlatform('sk-test', $http, eventDispatcher: $this->dispatcher());

        $platform->invoke('gpt-4o-mini', new MessageBag(Message::ofUser('hi')));

        self::assertIsArray($body);
        self::assertSame([], array_intersect(['num_ctx', 'num_thread', 'keep_alive', 'options'], array_keys($body)));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function ollamaChatBody(array $options): array
    {
        $chat = null;
        $http = new MockHttpClient(static function (string $_, string $url, array $request) use (&$chat) {
            if (str_ends_with($url, '/api/show')) {
                return new MockResponse('{"capabilities":["completion"]}');
            }
            $chat = json_decode($request['body'], true);

            return new MockResponse('{}');
        });
        $platform = OllamaFactory::createPlatform('http://ollama.test', httpClient: $http, eventDispatcher: $this->dispatcher());

        $platform->invoke('gemma4', new MessageBag(Message::ofUser('hi')), $options);

        self::assertIsArray($chat);

        return $chat;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return self::getContainer()->get(EventDispatcherInterface::class);
    }
}
