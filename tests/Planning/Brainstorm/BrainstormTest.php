<?php

namespace App\Tests\Planning\Brainstorm;

use App\Planning\PlanInput;
use App\Planning\Reference;
use App\Planning\ReferenceType;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Tests\Planning\PlanningTestCase;

final class BrainstormTest extends PlanningTestCase
{
    public function testAddedReferencesAreSavedInRefsAndEachGetsAJob(): void
    {
        $article = $this->planIn(ArticleState::Plan);
        $dir = sys_get_temp_dir();
        file_put_contents($dir.'/notes.md', '# notes');

        $crawler = $this->client->request('GET', '/plans/'.$article->getId().'/brainstorm');
        self::assertSelectorTextSame('nav.steps a[aria-current=page]', 'Brainstorming');
        $form = $crawler->selectButton('Add references')->form(['add_references[links]' => "https://symfony.com/doc\n\n"]);
        $form['add_references[docs]'][0]->upload($dir.'/notes.md');
        $this->client->submit($form);

        self::assertResponseRedirects('/plans/'.$article->getId().'/brainstorm');
        $references = $this->em()->getRepository(Reference::class)->findBy(['article' => $article], ['id' => 'ASC']);
        self::assertSame([ReferenceType::Link, ReferenceType::Doc], array_map(static fn (Reference $r) => $r->getType(), $references));
        self::assertSame(['https://symfony.com/doc', 'refs/notes.md'], array_map(static fn (Reference $r) => $r->getLocation(), $references));
        $root = self::getContainer()->getParameter('kernel.project_dir').'/var/test-articles/'.$article->getFolder();
        self::assertFileExists($root.'/refs/notes.md');
        self::assertSame(['extract_reference', 'extract_reference'], $this->jobSteps());
    }

    public function testBadUrlGives422AndAddsNothing(): void
    {
        $article = $this->planIn(ArticleState::Plan);

        $crawler = $this->client->request('GET', '/plans/'.$article->getId().'/brainstorm');
        $this->client->submit($crawler->selectButton('Add references')->form(['add_references[links]' => 'not a url']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->em()->getRepository(Reference::class)->findAll());
    }

    public function testAudienceReaderLevelAndIdeaAreSaved(): void
    {
        $article = $this->planIn(ArticleState::Plan);

        $this->client->request('POST', '/plans/'.$article->getId().'/brainstorm/notes', ['audience' => 'Symfony devs', 'readerLevel' => '', 'roughFlow' => "## Hooks\n- get, set"]);

        self::assertResponseStatusCodeSame(204);
        $this->em()->clear();
        $input = $this->em()->getRepository(PlanInput::class)->findOneBy(['article' => $article->getId()]);
        self::assertSame(['Symfony devs', null, "## Hooks\n- get, set"], [$input?->getAudience(), $input?->getReaderLevel(), $input?->getRoughFlow()]);
    }

    public function testNextWaitsWhileAReferenceIsBeingRead(): void
    {
        $article = $this->planIn(ArticleState::Plan);
        $this->em()->persist(new Reference($article, ReferenceType::Link, 'https://symfony.com'));
        $this->em()->flush();

        $this->client->request('GET', '/plans/'.$article->getId().'/brainstorm');
        self::assertSelectorExists('button[disabled]:contains("Next: outline")');
        self::assertSelectorTextContains('main', 'Reading 1 reference');

        $this->client->request('POST', '/plans/'.$article->getId().'/brainstorm/next');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(ArticleState::Plan, $this->reload($article)->getState());
    }

    public function testNextMovesToQuestionsAndQueuesTheAskJob(): void
    {
        $article = $this->planIn(ArticleState::Plan);
        $reference = new Reference($article, ReferenceType::Link, 'https://symfony.com');
        $reference->markUnreadable();
        $this->em()->persist($reference);
        $this->em()->flush();

        $this->client->request('POST', '/plans/'.$article->getId().'/brainstorm/next');

        self::assertResponseRedirects('/plans/'.$article->getId().'/questions');
        self::assertSame(ArticleState::Questions, $this->reload($article)->getState());
        self::assertSame(['ask_questions'], $this->jobSteps());
    }

    public function testNextOutlineSkipsQuestionsAndFlowsAndQueuesTheOutlineJob(): void
    {
        $article = $this->planIn(ArticleState::Plan);

        $this->client->request('POST', '/plans/'.$article->getId().'/brainstorm/next', ['to' => 'outline']);

        self::assertResponseRedirects('/plans/'.$article->getId().'/outline');
        self::assertSame(ArticleState::Outline, $this->reload($article)->getState());
        self::assertSame(['generate_outline'], $this->jobSteps());
    }

    public function testSkippedQuestionsAndFlowsSaySo(): void
    {
        $article = $this->planIn(ArticleState::Plan);
        $this->client->request('POST', '/plans/'.$article->getId().'/brainstorm/next', ['to' => 'outline']);

        $this->client->request('GET', '/plans/'.$article->getId().'/questions');
        self::assertSelectorTextContains('main', 'Skipped: this plan went straight to the outline.');
        $this->client->request('GET', '/plans/'.$article->getId().'/flows');
        self::assertSelectorTextContains('main', 'Skipped: this plan went straight to the outline.');
    }

    public function testDoneReferenceShowsTheSavedNotesInACollapsedPanel(): void
    {
        $article = $this->planIn(ArticleState::Plan);
        $reference = new Reference($article, ReferenceType::Link, 'https://symfony.com/doc');
        $reference->markDone('Symfony 8 uses #[Route] on controllers.');
        $this->em()->persist($reference);
        $this->em()->flush();

        $this->client->request('GET', '/plans/'.$article->getId().'/brainstorm');

        self::assertSelectorExists('details.reference:not([open])');
        self::assertSelectorTextContains('details.reference summary', 'https://symfony.com/doc');
        self::assertSelectorTextContains('details.reference .reference-digest', 'Symfony 8 uses #[Route] on controllers.');
    }

    public function testAfterNextTheBrainstormIsReadOnly(): void
    {
        $article = $this->planIn(ArticleState::Questions);
        $id = $article->getId();

        $this->client->request('GET', '/plans/'.$id.'/brainstorm');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('textarea');
        self::assertSelectorTextContains('.plan-input', 'PHP developers');
        self::assertSelectorTextContains('.notes', 'Typed properties, hooks');

        foreach (['/brainstorm', '/brainstorm/notes', '/brainstorm/next'] as $path) {
            $this->client->request('POST', '/plans/'.$id.$path, ['roughFlow' => 'late']);
            self::assertResponseStatusCodeSame(409, $path);
        }
    }

    /** @return list<string> */
    private function jobSteps(): array
    {
        $this->em()->clear();

        return array_map(static fn (LlmJob $j) => $j->getStep(), $this->em()->getRepository(LlmJob::class)->findBy([], ['id' => 'ASC']));
    }
}
