<?php

namespace App\Tests\Planning;

use App\Planning\FinalCheck\CheckArticle;
use App\Planning\Questions\AskQuestions;
use App\Planning\References\DigestReference;
use App\Planning\Share\SuggestPunchlines;
use DoctrineMigrations\Version20261103120000;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../migrations/Version20261103120000.php';

/** A failed job keeps its serialized message for Retry, so messages saved before the folders moved must still load. */
final class MovedJobMessagesTest extends TestCase
{
    public function testAMessageSavedBeforeTheMoveLoadsAsItsNewClass(): void
    {
        $old = 'App\Planning\DigestReferences\DigestReference';
        $saved = base64_encode(\sprintf('O:%d:"%s":2:{s:%d:"%s";i:6;s:11:"referenceId";i:7;}', \strlen($old), $old, \strlen($old) + 10, "\0{$old}\0llmJobId"));

        $message = unserialize(base64_decode(Version20261103120000::renamed($saved)));

        self::assertInstanceOf(DigestReference::class, $message);
        self::assertSame(6, $message->llmJobId());
        self::assertSame(7, $message->referenceId);
    }

    public function testBackAndForthGivesTheSameMessage(): void
    {
        $saved = base64_encode(serialize(new SuggestPunchlines(1, 2, 3, 'an idea')));

        $back = Version20261103120000::renamed($saved, back: true);

        self::assertNotSame($saved, $back);
        self::assertStringContainsString('App\Planning\SuggestPunchlines\SuggestPunchlines', base64_decode($back));
        self::assertSame($saved, Version20261103120000::renamed($back));
    }

    public function testASameNamedFolderOnlyChangesTheNamespace(): void
    {
        $saved = base64_encode(serialize(new AskQuestions(1, 2)));
        $old = Version20261103120000::renamed($saved, back: true);

        self::assertStringContainsString('App\Planning\AskQuestions\AskQuestions', base64_decode($old));
        self::assertInstanceOf(AskQuestions::class, unserialize(base64_decode(Version20261103120000::renamed($old))));
    }

    public function testAMessageThatDidNotMoveAndTextThatOnlyLooksLikeAClassStayAsTheyAre(): void
    {
        $unmoved = base64_encode(serialize(new CheckArticle(1, 2)));
        $goneClass = base64_encode('O:42:"App\Planning\GeneratePlanTitle\GeneratePlanTitle":0:{}');
        $idea = base64_encode(serialize(new SuggestPunchlines(1, 2, 3, 'App\Planning\DigestReferences\DigestReference')));

        self::assertSame($unmoved, Version20261103120000::renamed($unmoved));
        self::assertSame($goneClass, Version20261103120000::renamed($goneClass));
        self::assertStringContainsString('App\Planning\DigestReferences\DigestReference', base64_decode(Version20261103120000::renamed($idea)));
    }
}
