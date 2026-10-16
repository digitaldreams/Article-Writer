<?php

namespace App\Tests\Planning\RejectPlan;

use App\Planning\FlowOption;
use App\Planning\GenerateOutline\OutlineAnswer;
use App\Planning\GenerateOutline\ProposedItem;
use App\Planning\OutlineItem;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\ObjectResult;

final class RejectPlanTest extends PlanningTestCase
{
    public function testRejectToOutlineShowsRoundTwoBuiltWithTheReason(): void
    {
        $article = $this->reviewedPlan();
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return new ObjectResult(new OutlineAnswer([new ProposedItem('A real bug first', 'Then the fix.')]));
        });

        $this->client->request('POST', '/plans/'.$article->getId().'/reject', ['reason' => 'Too abstract', 'to' => 'outline']);
        $this->consume();
        $this->client->request('GET', '/plans/'.$article->getId().'/outline');

        self::assertSame(ArticleState::Outline, $this->reload($article)->getState());
        self::assertSelectorTextContains('.page-head', 'Round 2');
        self::assertSelectorExists('input[value="A real bug first"]');
        self::assertStringContainsString('rejected the last outline because: Too abstract', $prompt);
    }

    public function testRejectToFlowsShowsTheOldFlows(): void
    {
        $article = $this->reviewedPlan();

        $this->client->request('POST', '/plans/'.$article->getId().'/reject', ['reason' => 'Wrong angle', 'to' => 'flows']);
        $this->client->followRedirect();
        $this->client->followRedirect();

        self::assertSame(ArticleState::Flows, $this->reload($article)->getState());
        self::assertSelectorTextContains('.flow', 'Problem first');
    }

    public function testRejectNeedsAReason(): void
    {
        $article = $this->reviewedPlan();

        $this->client->request('POST', '/plans/'.$article->getId().'/reject', ['reason' => '', 'to' => 'outline']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Say why the outline is rejected.');
    }

    private function reviewedPlan(): Article
    {
        $article = $this->planIn(ArticleState::Finalize);
        $flow = new FlowOption($article, 'Problem first');
        $flow->choose();
        $this->em()->persist($flow);
        $this->em()->persist(new OutlineItem($article, 1, 0, 'The old pain', 'Untyped properties.'));
        $this->em()->flush();

        return $article;
    }
}
