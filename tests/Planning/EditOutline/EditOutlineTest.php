<?php

namespace App\Tests\Planning\EditOutline;

use App\Planning\FinalizeRequested;
use App\Planning\GenerateOutline\GenerateOutline;
use App\Planning\GenerateOutline\OutlineAnswer;
use App\Planning\GenerateOutline\ProposedItem;
use App\Planning\OutlineItem;
use App\Planning\OutlineItems;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class EditOutlineTest extends PlanningTestCase
{
    public function testAnEditSurvivesAReloadAndIsMarkedAsAHandEdit(): void
    {
        [$article, [$first]] = $this->outlineRoundOne();

        $this->client->request('POST', $this->itemUrl($article, $first), ['title' => 'The real pain', 'summary' => 'Bugs from untyped code.']);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/plans/'.$article->getId().'/outline');

        self::assertSelectorExists('input[value="The real pain"]');
        self::assertTrue($this->em()->find(OutlineItem::class, $first->getId())?->isEditedByHand());
    }

    public function testAnItemCanBeAddedAndRemoved(): void
    {
        [$article, [$first]] = $this->outlineRoundOne();

        $this->client->request('POST', '/plans/'.$article->getId().'/outline/items', ['title' => 'Property hooks', 'summary' => 'Get and set logic.']);
        $this->client->request('POST', $this->itemUrl($article, $first), ['remove' => '1']);

        self::assertSame(['Typed properties', 'Property hooks'], array_map(static fn (OutlineItem $i) => $i->getTitle(), $this->outline()->latest($this->reload($article))));
    }

    public function testADirectionChangeShowsRoundTwoWhenItsJobIsDone(): void
    {
        [$article] = $this->outlineRoundOne();
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return new ObjectResult(new OutlineAnswer([new ProposedItem('A real bug', 'Start with it.')]));
        });

        $this->client->request('POST', '/plans/'.$article->getId().'/outline/direction', ['instruction' => 'Start with a real bug']);
        $this->consume();
        $this->client->request('GET', '/plans/'.$article->getId().'/outline');

        self::assertSelectorTextContains('.page-head', 'Round 2');
        self::assertSelectorExists('input[value="A real bug"]');
        self::assertStringContainsString('The writer asks for this change: Start with a real bug', $prompt);
        self::assertStringContainsString('1. The old pain', $prompt);
    }

    public function testADirectionChangeNeedsAnInstruction(): void
    {
        [$article] = $this->outlineRoundOne();

        $this->client->request('POST', '/plans/'.$article->getId().'/outline/direction', ['instruction' => ' ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Say what should change.');
    }

    public function testAReplayOfTheSameRoundAddsNothing(): void
    {
        [$article] = $this->outlineRoundOne();
        $this->fakePrimary(static fn () => new ObjectResult(new OutlineAnswer([new ProposedItem('Extra', 'Should not appear.')])));

        self::getContainer()->get('messenger.bus.default')->dispatch(new GenerateOutline(1, (int) $article->getId(), 1));
        $this->consume();

        self::assertCount(2, $this->outline()->ofRound($this->reload($article), 1));
    }

    public function testFinalizeMovesToFinalizeAndFiresFinalizeRequested(): void
    {
        [$article] = $this->outlineRoundOne();
        $fired = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(FinalizeRequested::class, static function (FinalizeRequested $e) use (&$fired): void {
            $fired[] = [$e->articleId, $e->round];
        });

        $this->client->request('POST', '/plans/'.$article->getId().'/outline/finalize');

        self::assertSame(ArticleState::Finalize, $this->reload($article)->getState());
        self::assertSame([[$article->getId(), 1]], $fired);
    }

    public function testEditingOutsideTheOutlineStepIsAConflict(): void
    {
        [$article, [$first]] = $this->outlineRoundOne();
        $this->client->request('POST', '/plans/'.$article->getId().'/outline/finalize');

        $this->client->request('POST', $this->itemUrl($article, $first), ['title' => 'Late']);

        self::assertResponseStatusCodeSame(409);
    }

    /** @return array{Article, list<OutlineItem>} */
    private function outlineRoundOne(): array
    {
        $article = $this->planIn(ArticleState::Outline);
        $items = [new OutlineItem($article, 1, 0, 'The old pain', 'Untyped properties.'), new OutlineItem($article, 1, 1, 'Typed properties', 'What they fix.')];
        foreach ($items as $item) {
            $this->em()->persist($item);
        }
        $this->em()->flush();
        self::assertSame(0, $this->em()->getRepository(LlmJob::class)->count([]));

        return [$article, $items];
    }

    private function itemUrl(Article $article, OutlineItem $item): string
    {
        return '/plans/'.$article->getId().'/outline/items/'.$item->getId();
    }

    private function outline(): OutlineItems
    {
        return self::getContainer()->get(OutlineItems::class);
    }
}
