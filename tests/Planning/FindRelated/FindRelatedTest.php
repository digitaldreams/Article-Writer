<?php

namespace App\Tests\Planning\FindRelated;

use App\Planning\FindRelated\FindRelated;
use App\Planning\FindRelated\RelatedLink;
use App\Planning\FindRelated\RelatedReport;
use App\Tests\RenderingPlatform;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FindRelatedTest extends KernelTestCase
{
    private const CANDIDATES = [['id' => 5, 'title' => 'Service Container'], ['id' => 6, 'title' => 'SOLID'], ['id' => 7, 'title' => 'Messenger'], ['id' => 8, 'title' => 'Security']];

    public function testTheModelSeesTheTitleSubtitleHeadingsAndEveryCandidate(): void
    {
        $prompt = '';
        $this->fake(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return new ObjectResult(new RelatedReport([]));
        });

        $this->finder()->ask('Domain Driven Design', 'Design first', ['Entities', 'Aggregates'], self::CANDIDATES);

        self::assertStringContainsString('Domain Driven Design', $prompt);
        self::assertStringContainsString('Design first', $prompt);
        self::assertStringContainsString("- Entities\n- Aggregates", $prompt);
        self::assertStringContainsString('5: Service Container', $prompt);
        self::assertStringContainsString('8: Security', $prompt);
    }

    public function testAnIdThatIsNotInTheListIsDroppedAndADuplicateIsKeptOnce(): void
    {
        $this->fake(static fn () => new ObjectResult(new RelatedReport([new RelatedLink(99, 'invented'), new RelatedLink(5, 'a'), new RelatedLink(5, 'again'), new RelatedLink(6, 'b')])));

        $links = $this->finder()->ask('T', 'S', [], self::CANDIDATES);

        self::assertSame([5, 6], array_map(static fn (RelatedLink $l) => $l->id, $links));
        self::assertSame('a', $links[0]->reason);
    }

    public function testAtMostThreeLinksAreKept(): void
    {
        $this->fake(static fn () => new ObjectResult(new RelatedReport(array_map(static fn (array $c) => new RelatedLink($c['id'], 'r'), self::CANDIDATES))));

        self::assertCount(FindRelated::MAX_LINKS, $this->finder()->ask('T', 'S', [], self::CANDIDATES));
    }

    private function finder(): FindRelated
    {
        return self::getContainer()->get(FindRelated::class);
    }

    private function fake(\Closure $answer): void
    {
        self::bootKernel();
        RenderingPlatform::install(self::getContainer(), 'ollama', static fn ($model, $input, array $options) => $answer($input, $options));
    }
}
