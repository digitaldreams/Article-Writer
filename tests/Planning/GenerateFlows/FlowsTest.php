<?php

namespace App\Tests\Planning\GenerateFlows;

use App\Planning\FlowOption;
use App\Planning\GenerateFlows\FlowList;
use App\Planning\GenerateOutline\OutlineAnswer;
use App\Planning\GenerateOutline\ProposedItem;
use App\Planning\QuestionsDone;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use App\Tests\Planning\PlanningTestCase;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class FlowsTest extends PlanningTestCase
{
    public function testNextFromQuestionsGeneratesTheFlowsShownOnTheFlowsPage(): void
    {
        $this->fakeFlowsAndOutline();
        $article = $this->planIn(ArticleState::Questions);

        $this->client->request('POST', '/plans/'.$article->getId().'/questions/next');
        $this->consume();
        $this->client->request('GET', '/plans/'.$article->getId().'/flows');

        self::assertSelectorCount(2, '.flow');
        self::assertSelectorTextContains('.flow', 'Problem first');
    }

    public function testComingBackToFlowsDoesNotGenerateNewOnes(): void
    {
        $article = $this->planIn(ArticleState::Flows);
        $this->em()->persist(new FlowOption($article, 'Problem first'));
        $this->em()->flush();

        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new QuestionsDone((int) $article->getId()));

        self::assertSame(0, $this->em()->getRepository(LlmJob::class)->count(['step' => 'generate_flows']));
    }

    public function testOneFlowIsNotEnoughAndFailsTheJob(): void
    {
        $this->fakePrimary(static fn () => new ObjectResult(new FlowList(['Only one'])));
        $article = $this->planIn(ArticleState::Questions);

        $this->client->request('POST', '/plans/'.$article->getId().'/questions/next');
        $this->consume();

        $job = $this->em()->getRepository(LlmJob::class)->findOneBy(['step' => 'generate_flows']);
        self::assertSame(LlmJobStatus::Failed, $job?->getStatus());
    }

    public function testAnEditedFlowIsSavedChosenAndStartsRoundOne(): void
    {
        $this->fakeFlowsAndOutline();
        $article = $this->planIn(ArticleState::Flows);
        $flows = [new FlowOption($article, 'Problem first'), new FlowOption($article, 'History first')];
        foreach ($flows as $flow) {
            $this->em()->persist($flow);
        }
        $this->em()->flush();

        $this->client->request('POST', '/plans/'.$article->getId().'/flows/'.$flows[1]->getId(), ['description' => ' History first, told as a story ']);
        $this->consume();

        self::assertSame(ArticleState::Outline, $this->reload($article)->getState());
        self::assertSame(
            [['Problem first', false], ['History first, told as a story', true]],
            array_map(static fn (FlowOption $f) => [$f->getDescription(), $f->isChosen()], $this->em()->getRepository(FlowOption::class)->findBy([], ['id' => 'ASC'])),
        );
        $this->client->request('GET', '/plans/'.$article->getId().'/outline');
        self::assertSelectorTextContains('.page-head', 'Round 1');
        self::assertSelectorCount(2, '.outline-item');
    }

    public function testChoosingAFlowInTheWrongStateIsAConflict(): void
    {
        $article = $this->planIn(ArticleState::Outline);
        $flow = new FlowOption($article, 'Problem first');
        $this->em()->persist($flow);
        $this->em()->flush();

        $this->client->request('POST', '/plans/'.$article->getId().'/flows/'.$flow->getId(), ['description' => 'Problem first']);
        self::assertResponseStatusCodeSame(409);

        $this->client->request('POST', '/plans/'.$article->getId().'/flows', ['description' => 'My own']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testTheAuthorsOwnFlowIsChosenAndStartsRoundOne(): void
    {
        $this->fakeFlowsAndOutline();
        $article = $this->planIn(ArticleState::Flows);
        $this->em()->persist(new FlowOption($article, 'Problem first'));
        $this->em()->flush();

        $this->client->request('GET', '/plans/'.$article->getId().'/flows');
        $this->client->submit($this->client->getCrawler()->selectButton('Use my flow')->form(['description' => 'A checklist, one hook per step']));
        $this->consume();

        self::assertSame(ArticleState::Outline, $this->reload($article)->getState());
        self::assertSame(
            [['Problem first', false], ['A checklist, one hook per step', true]],
            array_map(static fn (FlowOption $f) => [$f->getDescription(), $f->isChosen()], $this->em()->getRepository(FlowOption::class)->findBy([], ['id' => 'ASC'])),
        );
    }

    public function testAnEmptyFlowIs422AndChoosesNothing(): void
    {
        $article = $this->planIn(ArticleState::Flows);
        $flow = new FlowOption($article, 'Problem first');
        $this->em()->persist($flow);
        $this->em()->flush();

        $this->client->request('POST', '/plans/'.$article->getId().'/flows/'.$flow->getId(), ['description' => '  ']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/plans/'.$article->getId().'/flows', ['description' => '']);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(ArticleState::Flows, $this->reload($article)->getState());
        self::assertCount(1, $this->em()->getRepository(FlowOption::class)->findAll());
    }

    private function fakeFlowsAndOutline(): void
    {
        $this->fakePrimary(static fn ($input, array $options) => match ($options['response_format'] ?? null) {
            FlowList::class => new ObjectResult(new FlowList(['Problem first: a bug, then the fix.', 'History first: PHP 5 to 8.'])),
            default => new ObjectResult(new OutlineAnswer([new ProposedItem('The old pain', 'Untyped properties.'), new ProposedItem('Typed properties', 'What they fix.')])),
        });
    }
}
