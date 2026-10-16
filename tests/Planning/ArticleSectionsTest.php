<?php

namespace App\Tests\Planning;

use App\Planning\ArticleSections;
use PHPUnit\Framework\TestCase;

final class ArticleSectionsTest extends TestCase
{
    public function testTheIntroductionHoldsTheTextBeforeTheFirstHeading(): void
    {
        $sections = ArticleSections::split("Why this matters.\n\n## Setup\n\nInstall it.\n");

        self::assertSame(['Introduction' => "Why this matters.\n\n", 'Setup' => "\nInstall it.\n"], $sections);
    }

    public function testAnEmptyIntroductionIsLeftOut(): void
    {
        self::assertSame(['Setup'], array_keys(ArticleSections::split("## Setup\n\nInstall it.\n")));
    }

    public function testTheTitleAndSubtitleLinesBelongToNoSection(): void
    {
        $sections = ArticleSections::split("# Typed at last\n## Hooks in PHP 8.4\n\nIntro.\n\n## Setup\n\nInstall it.\n");

        self::assertSame(['Introduction' => "\nIntro.\n\n", 'Setup' => "\nInstall it.\n"], $sections);
    }

    public function testThirdLevelHeadingsStayInsideTheirSection(): void
    {
        self::assertSame(['Setup' => "\n### Install\n\nRun it.\n"], ArticleSections::split("## Setup\n\n### Install\n\nRun it.\n"));
    }

    public function testTheSplitIgnoresHeadingsInsideCodeBlocks(): void
    {
        $body = "## Setup\n\n```markdown\n## Not a heading\n```\n\n## Usage\n\nCall it.\n";

        self::assertSame(['Setup' => "\n```markdown\n## Not a heading\n```\n\n", 'Usage' => "\nCall it.\n"], ArticleSections::split($body));
    }

    public function testReplaceChangesOnlyThatSectionAndKeepsTheBlankLines(): void
    {
        $body = "# Title\n## Subtitle\n\nIntro.\n\n## Setup\n\nInstall it.\n\n## Usage\n\nCall it.\n";

        self::assertSame(
            "# Title\n## Subtitle\n\nIntro.\n\n## Setup\n\nInstall it with Composer.\n\n## Usage\n\nCall it.\n",
            ArticleSections::replace($body, 'Setup', "  Install it with Composer.\n"),
        );
    }

    public function testReplaceTheIntroductionKeepsTheTitleLines(): void
    {
        self::assertSame(
            "# Title\n\nA better intro.\n\n## Setup\n\nInstall it.\n",
            ArticleSections::replace("# Title\n\nIntro.\n\n## Setup\n\nInstall it.\n", 'Introduction', 'A better intro.'),
        );
    }
}
