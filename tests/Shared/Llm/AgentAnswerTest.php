<?php

namespace App\Tests\Shared\Llm;

use App\Planning\CreatePlan\PlanTitleAnswer;
use App\Shared\Llm\AgentAnswer;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;

final class AgentAnswerTest extends TestCase
{
    public function testReadsTheStructuredPartOfAReasoningModelAnswer(): void
    {
        $answer = AgentAnswer::object($this->answer(new MultiPartResult([new ThinkingResult('thinking'), new ObjectResult(new PlanTitleAnswer('Hooks'))])), PlanTitleAnswer::class);

        self::assertSame('Hooks', $answer->title);
    }

    public function testFailsWhenNoPartIsTheExpectedObject(): void
    {
        $this->expectExceptionMessage('The model did not answer with a PlanTitleAnswer.');

        AgentAnswer::object($this->answer(new TextResult('{not json')), PlanTitleAnswer::class);
    }

    public function testReadsTheTextOfAReasoningModelAnswer(): void
    {
        self::assertSame('A digest', AgentAnswer::text($this->answer(new MultiPartResult([new ThinkingResult('thinking'), new TextResult(' A digest ')]))));
    }

    private function answer(ResultInterface $result): Execution
    {
        return (new Agent(new InMemoryPlatform(static fn () => $result), 'test'))->call('hi');
    }
}
