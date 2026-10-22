<?php

namespace App\Tests\Author\AnalyzeWriting;

use App\Planning\GenerateOutline\OutlineAnswer;
use App\Planning\GenerateOutline\ProposedItem;
use App\Planning\OutlineItem;
use App\Shared\Article\ArticleState;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\ObjectResult;

final class OutlineSeesStructureOnlyTest extends PlanningTestCase
{
    public function testTheOutlineRequestHasTheStructureAndNoVoice(): void
    {
        $this->author->setWritingProfile(['openingType' => 'a real bug story'], ['tone' => 'dry humor', 'samples' => 'A verbatim paragraph.']);
        $article = $this->planIn(ArticleState::Outline);
        $this->em()->persist(new OutlineItem($article, 1, 0, 'The old pain', 'Untyped properties.'));
        $this->em()->flush();
        $prompt = '';
        $this->fakePrimary(static function ($input) use (&$prompt) {
            $prompt = (string) $input->getUserMessage()?->asText();

            return new ObjectResult(new OutlineAnswer([new ProposedItem('A real bug', 'Start with it.')]));
        });

        $this->client->request('POST', '/plans/'.$article->getId().'/outline/direction', ['instruction' => 'Start with a real bug']);
        $this->consume();

        self::assertStringContainsString('openingType: a real bug story', $prompt);
        self::assertStringNotContainsString('dry humor', $prompt);
        self::assertStringNotContainsString('A verbatim paragraph.', $prompt);
    }
}
