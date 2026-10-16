<?php

namespace App\Tests\Planning\FinalCheckup;

use App\Planning\FinalCheckup\CheckupAnswer;
use App\Planning\FinalCheckup\PolishedItem;
use App\Planning\OutlineItem;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\ObjectResult;

final class FinalCheckupTest extends PlanningTestCase
{
    public function testFinalizeShowsTheIssuesAndBeforeAfterPerChangedItem(): void
    {
        [$article] = $this->finalizedOutline(new CheckupAnswer(['Item 2 repeats item 1'], [
            new PolishedItem('The old pain', 'Untyped properties.'),
            new PolishedItem('Typed properties', 'What they fix in real code.'),
        ]));

        $this->client->request('GET', '/plans/'.$article->getId().'/review');

        self::assertSelectorTextContains('.issues', 'Item 2 repeats item 1');
        self::assertSelectorCount(1, '.before-after');
        self::assertSelectorTextContains('.before-after', 'What they fix in real code.');
    }

    public function testAcceptCopiesTheSuggestionInAndRejectKeepsTheOriginal(): void
    {
        [$article, [$first, $second]] = $this->finalizedOutline(new CheckupAnswer([], [
            new PolishedItem('The old pains', 'Untyped properties hurt.'),
            new PolishedItem('Typed property', 'What they fixes.'),
        ]));

        $this->client->request('POST', $this->itemUrl($article, $first), ['decision' => 'accept']);
        $this->client->request('POST', $this->itemUrl($article, $second), ['decision' => 'reject']);

        $this->em()->clear();
        $first = $this->em()->find(OutlineItem::class, $first->getId());
        $second = $this->em()->find(OutlineItem::class, $second->getId());
        self::assertSame(['The old pains', 'Untyped properties hurt.', null], [$first?->getTitle(), $first?->getSummary(), $first?->getSuggestedTitle()]);
        self::assertSame(['Typed properties', 'What they fix.', null], [$second?->getTitle(), $second?->getSummary(), $second?->getSuggestedTitle()]);
    }

    public function testAPolishWithAMissingItemFailsTheJob(): void
    {
        $this->finalizedOutline(new CheckupAnswer([], [new PolishedItem('Only one', 'x')]));

        self::assertSame(LlmJobStatus::Failed, $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'final_checkup'])?->getStatus());
    }

    /** @return array{Article, list<OutlineItem>} */
    private function finalizedOutline(CheckupAnswer $answer): array
    {
        $this->fakePrimary(static fn () => new ObjectResult($answer));
        $article = $this->planIn(ArticleState::Outline);
        $items = [new OutlineItem($article, 1, 0, 'The old pain', 'Untyped properties.'), new OutlineItem($article, 1, 1, 'Typed properties', 'What they fix.')];
        foreach ($items as $item) {
            $this->em()->persist($item);
        }
        $this->em()->flush();

        $this->client->request('POST', '/plans/'.$article->getId().'/outline/finalize');
        $this->consume();

        return [$article, $items];
    }

    private function itemUrl(Article $article, OutlineItem $item): string
    {
        return '/plans/'.$article->getId().'/review/items/'.$item->getId();
    }
}
