<?php

namespace App\Tests\Author\ListLibrary;

use App\Author\AnalyzeWriting\ExtractWritingNotes;
use App\Author\WritingNote;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ListLibraryTest extends WebTestCase
{
    use LogsInAuthor;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->logIn($this->client);
    }

    public function testImportedArticlesAreListedWithDateSourceAndAnalyzed(): void
    {
        $analyzed = $this->imported('Hooks', 'https://example.com/hooks', new \DateTimeImmutable('2024-03-05'));
        $this->em()->persist(new WritingNote($analyzed, 'a story', 5, 'by feature', true, 'summary', 'friendly', 'short sentences', 'questions', 'snippet first', ['A sample.']));
        $this->imported('Enums');
        $this->em()->persist(Article::plan($this->author, 'a-plan', 'A plan'));
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/author/library');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('.library-article');
        self::assertCount(2, $rows);
        $hooks = $rows->reduce(static fn ($r) => str_contains($r->text(), 'Hooks'));
        self::assertStringContainsString('2024-03-05', $hooks->text());
        self::assertSame('https://example.com/hooks', $hooks->filter('a')->attr('href'));
        self::assertStringContainsString('yes', $hooks->text());
        self::assertStringContainsString('no', $rows->reduce(static fn ($r) => str_contains($r->text(), 'Enums'))->text());
    }

    public function testDeleteRemovesTheArticleItsNotesAndItsFolder(): void
    {
        $article = $this->imported('Hooks');
        $this->em()->persist(new WritingNote($article, 'a story', 5, 'by feature', true, 'summary', 'friendly', 'short sentences', 'questions', 'snippet first', ['A sample.']));
        $this->em()->flush();
        $id = $article->getId();
        $path = self::getContainer()->get(ArticleFolder::class)->pathOf($article->getFolder(), 'article.md');

        $this->client->request('POST', "/author/library/$id/delete");

        self::assertResponseRedirects('/author/library', 303);
        $this->em()->clear();
        self::assertNull($this->em()->find(Article::class, $id));
        self::assertSame([], $this->em()->getRepository(WritingNote::class)->findBy(['user' => $this->author]));
        self::assertFileDoesNotExist($path);
    }

    public function testDeleteWhileAJobIsOpenIs409(): void
    {
        $article = $this->imported('Hooks');
        self::getContainer()->get(LlmJobs::class)->start($article, 'extract_writing_notes', 'secondary', static fn (int $id) => new ExtractWritingNotes($id, (int) $article->getId()));

        $this->client->request('POST', '/author/library/'.$article->getId().'/delete');

        self::assertResponseStatusCodeSame(409);
    }

    public function testAPlanIsNotInTheLibrary(): void
    {
        $plan = Article::plan($this->author, 'a-plan', 'A plan');
        $this->em()->persist($plan);
        $this->em()->flush();

        $this->client->request('POST', '/author/library/'.$plan->getId().'/delete');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherAuthorsArticleIs404(): void
    {
        $theirs = Article::imported(self::newAuthor(), 'theirs', 'Theirs');
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->em()->clear();

        $this->client->request('POST', '/author/library/'.$theirs->getId().'/delete');

        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->em()->find(Article::class, $theirs->getId()));
    }

    private function imported(string $title, ?string $sourceUrl = null, ?\DateTimeImmutable $publishedAt = null): Article
    {
        $article = Article::imported($this->author, strtolower($title), $title, $sourceUrl, $publishedAt);
        $this->em()->persist($article);
        $this->em()->flush();
        self::getContainer()->get(ArticleFolder::class)->write($article->getFolder(), 'article.md', ['title' => $title], "x\n");

        return $article;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
