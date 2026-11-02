<?php

namespace App\Tests\Shared\Llm;

use App\Planning\FinalCheck\RefactorAnswer;
use App\Shared\Llm\AgentAnswer;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

final class AgentAnswerTest extends TestCase
{
    public function testReadsTheStructuredPartOfAReasoningModelAnswer(): void
    {
        $answer = AgentAnswer::object($this->answer(new MultiPartResult([new ThinkingResult('thinking'), new ObjectResult(new RefactorAnswer('Hooks', []))])), RefactorAnswer::class);

        self::assertSame('Hooks', $answer->body);
    }

    public function testFailsWhenNoPartIsTheExpectedObject(): void
    {
        $this->expectExceptionMessage('The model did not answer with a RefactorAnswer.');

        AgentAnswer::object($this->answer(new TextResult('{not json')), RefactorAnswer::class);
    }

    public function testReadsTheTextOfAReasoningModelAnswer(): void
    {
        self::assertSame('A digest', AgentAnswer::text($this->answer(new MultiPartResult([new ThinkingResult('thinking'), new TextResult(' A digest ')]))));
    }

    private function answer(ResultInterface $result): Execution
    {
        return (new Agent(MockPlatformFactory::createPlatform(static fn () => $result), 'test'))->call('hi');
    }
}
