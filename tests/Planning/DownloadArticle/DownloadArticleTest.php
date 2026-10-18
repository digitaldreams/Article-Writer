<?php

namespace App\Tests\Planning\DownloadArticle;

use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;
use App\Tests\Planning\PlanningTestCase;

final class DownloadArticleTest extends PlanningTestCase
{
    public function testTheArticlePageDownloadsArticleMdNamedAfterTheTitle(): void
    {
        $article = $this->planIn(ArticleState::Written);
        self::getContainer()->get(ArticleFolder::class)->write($article->getFolder(), 'article.md', ['title' => 'PHP 8 features'], "## Setup\n\nInstall it.\n");

        $this->client->request('GET', '/plans/'.$article->getId().'/article');
        $this->client->clickLink('Download .md');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-disposition', 'attachment; filename=php-8-features.md');
        self::assertStringContainsString("## Setup\n\nInstall it.\n", $this->client->getInternalResponse()->getContent());
    }

    public function testDownloadingBeforeTheArticleIsWrittenIsAConflict(): void
    {
        $article = $this->planIn(ArticleState::Approved);

        $this->client->request('GET', '/plans/'.$article->getId().'/article/download');
        self::assertResponseStatusCodeSame(409);
    }
}
