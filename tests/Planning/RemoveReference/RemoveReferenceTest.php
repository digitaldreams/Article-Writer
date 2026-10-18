<?php

namespace App\Tests\Planning\RemoveReference;

use App\Planning\DigestReferences\ExtractReference;
use App\Planning\Reference;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Tests\Planning\PlanningTestCase;

final class RemoveReferenceTest extends PlanningTestCase
{
    public function testUnreadableReferenceIsRemovedWithItsFailedJobCard(): void
    {
        $article = $this->planIn(ArticleState::Plan);
        $wrong = new Reference($article, 'https://exampel.com');
        $wrong->markUnreadable();
        $kept = new Reference($article, 'https://symfony.com/doc');
        $kept->markDone('Notes');
        $this->em()->persist($wrong);
        $this->em()->persist($kept);
        $this->em()->flush();
        $this->em()->persist($this->failedExtractJob($wrong));
        $this->em()->persist($this->failedExtractJob($kept));
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/plans/'.$article->getId().'/brainstorm');
        $this->client->submit($crawler->filter('button[aria-label="Remove https://exampel.com"]')->form());

        self::assertResponseRedirects('/plans/'.$article->getId().'/brainstorm');
        $this->em()->clear();
        self::assertSame(['https://symfony.com/doc'], array_map(static fn (Reference $r) => $r->getLocation(), $this->em()->getRepository(Reference::class)->findAll()));
        self::assertSame([$kept->getId()], array_map(static fn (LlmJob $j) => $j->storedMessage()->referenceId ?? null, $this->em()->getRepository(LlmJob::class)->findAll()));
    }

    public function testReferenceBeingReadHasNoRemoveAndGives409(): void
    {
        $article = $this->planIn(ArticleState::Plan);
        $reading = new Reference($article, 'https://symfony.com');
        $this->em()->persist($reading);
        $this->em()->flush();

        $this->client->request('GET', '/plans/'.$article->getId().'/brainstorm');
        self::assertSelectorNotExists('.reference-remove');

        $this->client->request('POST', '/references/'.$reading->getId().'/remove');
        self::assertResponseStatusCodeSame(409);
    }

    public function testAfterNextReferencesCannotBeRemoved(): void
    {
        $article = $this->planIn(ArticleState::Questions);
        $reference = new Reference($article, 'https://symfony.com');
        $reference->markDone('Notes');
        $this->em()->persist($reference);
        $this->em()->flush();

        $this->client->request('GET', '/plans/'.$article->getId().'/brainstorm');
        self::assertSelectorNotExists('.reference-remove');

        $this->client->request('POST', '/references/'.$reference->getId().'/remove');
        self::assertResponseStatusCodeSame(409);
    }

    public function testUnknownReferenceGives404(): void
    {
        $this->client->request('POST', '/references/999999/remove');

        self::assertResponseStatusCodeSame(404);
    }

    private function failedExtractJob(Reference $reference): LlmJob
    {
        $job = new LlmJob($reference->getArticle(), 'extract_reference', 'io', 'http', 'fetch');
        $this->em()->persist($job);
        $this->em()->flush();
        $job->storeMessage(new ExtractReference((int) $job->getId(), (int) $reference->getId()));
        $job->markFailed('No readable text.');

        return $job;
    }
}
