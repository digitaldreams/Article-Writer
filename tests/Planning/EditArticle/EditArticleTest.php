<?php

namespace App\Tests\Planning\EditArticle;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;

final class EditArticleTest extends PlanningTestCase
{
    private const FRONT_MATTER = ['title' => 'PHP 8 features', 'subtitle' => 'Typed at last'];
    private const BODY = "# PHP 8 features\n## Typed at last\n\nIntro.\n\n## Setup\n\nInstall it.\n\n## Usage\n\nCall it.\n";

    public function testTheArticleReadsSectionBySectionEachWithItsOwnEditLink(): void
    {
        $article = $this->writtenArticle(self::BODY);

        $this->client->request('GET', '/plans/'.$article->getId().'/article');

        self::assertSelectorTextSame('.article-preview > h1', 'PHP 8 features');
        self::assertSelectorCount(3, '.article-preview section.article-section');
        self::assertSelectorTextContains('#section-2 h2', 'Setup');
        self::assertSelectorTextContains('#section-2', 'Install it.');
        foreach ([1, 2, 3] as $n) {
            self::assertSelectorExists('#section-'.$n.' a.section-edit[href$="?section='.$n.'#section-'.$n.'"]');
        }
        self::assertSelectorNotExists('textarea');
    }

    public function testEditingOneSectionShowsOnlyItsEditorAndKeepsTheOthersReadable(): void
    {
        $article = $this->writtenArticle(self::BODY);

        $this->client->request('GET', '/plans/'.$article->getId().'/article?section=2');

        self::assertSelectorCount(1, 'textarea[name="text"][data-controller="markdown-editor"]');
        self::assertSelectorTextSame('#section-2 textarea[name="text"]', '## Setup Install it.');
        self::assertSelectorExists('#section-2 input[name="heading"][value="Setup"]');
        // Kept across the poll reloads, but only for this section: ✎ on another section must load that section's text.
        self::assertSelectorExists('#section-2 form#section-editor-2[data-turbo-permanent]');
        self::assertSelectorExists('#section-2 textarea[name="instruction"]');
        self::assertSelectorExists('#section-2 a[href$="/article#section-2"]:contains("Done")');
        self::assertSelectorTextContains('#section-3', 'Call it.');
        self::assertSelectorNotExists('#section-3 textarea');
        self::assertSelectorNotExists('[data-action="medium-copy#copy"]');
    }

    public function testASectionSaveReplacesOnlyThatSectionKeepsTheFrontMatterAndTouchesTheArticle(): void
    {
        $article = $this->writtenArticle(self::BODY);
        $before = new \DateTimeImmutable('-1 hour');
        $this->em()->createQuery('UPDATE '.Article::class.' a SET a.updatedAt = :at')->setParameter('at', $before)->execute();

        $this->client->request('POST', '/plans/'.$article->getId().'/article/sections', ['heading' => 'Setup', 'text' => "## Setup\r\n\r\nInstall it with Composer.\r\n"]);

        self::assertResponseStatusCodeSame(204);
        $file = $this->folder()->read($article->getFolder(), 'article.md');
        self::assertSame(['plan_id' => $article->getId()] + self::FRONT_MATTER, $file['frontMatter']);
        self::assertSame(str_replace('Install it.', 'Install it with Composer.', self::BODY), $file['body']);
        $this->em()->clear();
        self::assertGreaterThan($before, $this->em()->find(Article::class, $article->getId())?->getUpdatedAt());
    }

    public function testEditingTheHeadingLineRenamesTheSection(): void
    {
        $article = $this->writtenArticle(self::BODY);

        $this->client->request('POST', '/plans/'.$article->getId().'/article/sections', ['heading' => 'Setup', 'text' => "## Installation\r\n\r\nInstall it.\r\n"]);

        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('X-Section-Heading', 'Installation');
        self::assertSame(str_replace('## Setup', '## Installation', self::BODY), $this->folder()->read($article->getFolder(), 'article.md')['body']);
    }

    public function testASectionCannotTakeTheHeadingOfAnotherSection(): void
    {
        $article = $this->writtenArticle(self::BODY);

        $this->client->request('POST', '/plans/'.$article->getId().'/article/sections', ['heading' => 'Usage', 'text' => "## Setup\n\nCall it."]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::BODY, $this->folder()->read($article->getFolder(), 'article.md')['body']);
    }

    public function testASectionWithoutItsHeadingLineIsNotSaved(): void
    {
        $article = $this->writtenArticle(self::BODY);

        $this->client->request('POST', '/plans/'.$article->getId().'/article/sections', ['heading' => 'Setup', 'text' => 'Install it.']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::BODY, $this->folder()->read($article->getFolder(), 'article.md')['body']);
    }

    public function testTheIntroductionIsEditedWithoutAHeadingLine(): void
    {
        $article = $this->writtenArticle(self::BODY);

        $this->client->request('GET', '/plans/'.$article->getId().'/article?section=1');
        self::assertSelectorTextSame('#section-1 textarea[name="text"]', 'Intro.');

        $this->client->request('POST', '/plans/'.$article->getId().'/article/sections', ['heading' => 'Introduction', 'text' => 'Hello.']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(str_replace('Intro.', 'Hello.', self::BODY), $this->folder()->read($article->getFolder(), 'article.md')['body']);
    }

    public function testSavingAnUnknownSectionIsAFormErrorAndBeforeTheArticleIsWrittenAConflict(): void
    {
        $article = $this->writtenArticle(self::BODY);
        $this->client->request('POST', '/plans/'.$article->getId().'/article/sections', ['heading' => 'Nope', 'text' => "## Nope\n\nx"]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::BODY, $this->folder()->read($article->getFolder(), 'article.md')['body']);

        $approved = $this->planIn(ArticleState::Approved);
        $this->client->request('POST', '/plans/'.$approved->getId().'/article/sections', ['heading' => 'Setup', 'text' => 'x']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testThereIsNoWholeArticleEditorAnyMore(): void
    {
        $article = $this->writtenArticle(self::BODY);

        $this->client->request('GET', '/plans/'.$article->getId().'/article?edit=1');
        self::assertSelectorNotExists('textarea');
        self::assertSelectorNotExists('a:contains("Edit as Markdown")');
        self::assertSelectorExists('.article-preview');

        $this->client->request('POST', '/plans/'.$article->getId().'/article', ['body' => 'x']);
        self::assertResponseStatusCodeSame(405);
        self::assertSame(self::BODY, $this->folder()->read($article->getFolder(), 'article.md')['body']);
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
