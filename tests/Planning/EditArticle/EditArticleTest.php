<?php

namespace App\Tests\Planning\EditArticle;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;

final class EditArticleTest extends PlanningTestCase
{
    private const FRONT_MATTER = ['title' => 'PHP 8 features', 'subtitle' => 'Typed at last'];

    public function testEditSwapsThePreviewForTheEditorAndKeepsTheRestOfThePage(): void
    {
        $article = $this->writtenArticle("## Setup\n\nInstall it.\n");

        $this->client->request('GET', '/plans/'.$article->getId().'/article');
        $this->client->clickLink('✎ Edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('textarea[name="body"]', 'Install it.');
        self::assertSelectorNotExists('.article-preview');
        self::assertSelectorTextSame('nav.steps [aria-current]', 'Article');
        self::assertSelectorTextContains('.notice', 'article.md');
        self::assertSelectorExists('.notice a[href^="vscode://file/"][href*="article.md"]');

        $this->client->clickLink('Done');
        self::assertSelectorTextContains('.article-preview', 'Install it.');
    }

    public function testASaveRewritesTheBodyKeepsTheFrontMatterAndTouchesTheArticle(): void
    {
        $article = $this->writtenArticle("## Setup\n\nInstal it.\n");
        $before = new \DateTimeImmutable('-1 hour');
        $this->em()->createQuery('UPDATE '.Article::class.' a SET a.updatedAt = :at')->setParameter('at', $before)->execute();

        $this->client->request('POST', '/plans/'.$article->getId().'/article', ['body' => "## Setup\r\n\r\nInstall it.\r\n"]);

        self::assertResponseStatusCodeSame(204);
        $file = $this->folder()->read($article->getFolder(), 'article.md');
        self::assertSame(['plan_id' => $article->getId()] + self::FRONT_MATTER, $file['frontMatter']);
        self::assertSame("## Setup\n\nInstall it.\n", $file['body']);
        $this->em()->clear();
        self::assertGreaterThan($before, $this->em()->find(Article::class, $article->getId())?->getUpdatedAt());
    }

    public function testSavingBeforeTheArticleIsWrittenIsAConflict(): void
    {
        $article = $this->planIn(ArticleState::Approved);

        $this->client->request('POST', '/plans/'.$article->getId().'/article', ['body' => 'x']);
        self::assertResponseStatusCodeSame(409);
    }

    private function writtenArticle(string $body): Article
    {
        $article = $this->planIn(ArticleState::Written);
        $this->folder()->write($article->getFolder(), 'article.md', ['plan_id' => $article->getId()] + self::FRONT_MATTER, $body);

        return $article;
    }

    private function folder(): ArticleFolder
    {
        return self::getContainer()->get(ArticleFolder::class);
    }
}
