<?php

namespace App\Tests\Planning\FindRelated;

use App\Planning\FindRelated\FindRelated;
use App\Planning\FindRelated\FitVerdict;
use App\Planning\FindRelated\JudgeRelated;
use App\Planning\FindRelated\RelatedLink;
use App\Tests\RenderingPlatform;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class JudgeRelatedTest extends KernelTestCase
{
    private const CANDIDATES = [
        ['id' => 5, 'title' => 'Service Container', 'subtitle' => 'DI', 'headings' => ['Autowiring']],
        ['id' => 6, 'title' => 'SOLID', 'subtitle' => 'Principles', 'headings' => ['Single responsibility']],
        ['id' => 7, 'title' => 'Messenger', 'subtitle' => 'Queues', 'headings' => ['Buses']],
        ['id' => 8, 'title' => 'Security', 'subtitle' => 'Voters', 'headings' => ['Firewalls']],
    ];

    public function testEachJudgeSeesTheArticleInProgressAndExactlyOneCandidate(): void
    {
        $prompts = [];
        $this->fake(static function ($input) use (&$prompts) {
            $prompts[] = (string) $input->getUserMessage()?->asText();

            return new ObjectResult(new FitVerdict('no', false));
        });

        $this->judge()->ask('Domain Driven Design', 'Design first', ['Entities'], self::CANDIDATES);

        self::assertCount(4, $prompts);
        foreach ($prompts as $prompt) {
            self::assertStringContainsString('Domain Driven Design', $prompt);
            self::assertStringContainsString('- Entities', $prompt);
        }
        self::assertStringContainsString('Autowiring', $prompts[0]);
        self::assertStringNotContainsString('SOLID', $prompts[0], 'a judge never sees the other candidates');
        self::assertStringNotContainsString('Autowiring', $prompts[1]);
    }

    public function testOnlyCandidatesTheJudgeSaysFitAreLinkedWithTheirOwnReason(): void
    {
        $answers = [new FitVerdict('builds on DI', true), new FitVerdict('broad', false), new FitVerdict('queues', true), new FitVerdict('no', false)];
        $this->fake(static function () use (&$answers) {
            return new ObjectResult(array_shift($answers));
        });

        $links = $this->judge()->ask('T', 'S', [], self::CANDIDATES);

        self::assertEquals([new RelatedLink(5, 'builds on DI'), new RelatedLink(7, 'queues')], $links);
    }

    public function testAtMostThreeLinksAndTheCandidateOrderDecidesWhichOnes(): void
    {
        $this->fake(static fn () => new ObjectResult(new FitVerdict('fits', true)));

        $links = $this->judge()->ask('T', 'S', [], self::CANDIDATES);

        self::assertSame([5, 6, 7], array_map(static fn (RelatedLink $l) => $l->id, $links));
        self::assertCount(FindRelated::MAX_LINKS, $links);
    }

    private function judge(): JudgeRelated
    {
        return self::getContainer()->get(JudgeRelated::class);
    }

    private function fake(\Closure $answer): void
    {
        self::bootKernel();
        RenderingPlatform::install(self::getContainer(), 'ollama', static fn ($model, $input, array $options) => $answer($input, $options));
    }
}
