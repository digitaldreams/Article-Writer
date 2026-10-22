<?php

namespace App\Tests\Author\ShowPrompts;

use App\Tests\LogsInAuthor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShowPromptsTest extends WebTestCase
{
    use LogsInAuthor;

    public function testEveryPromptFileIsListedWithItsText(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $crawler = $client->request('GET', '/author/prompts');

        self::assertResponseIsSuccessful();
        $files = glob(self::getContainer()->getParameter('kernel.project_dir').'/prompts/*.md') ?: [];
        self::assertNotEmpty($files);
        self::assertSame(array_map('basename', $files), $crawler->filter('.prompt h2')->each(static fn ($h) => $h->text()));
        self::assertSame(array_map('file_get_contents', $files), $crawler->filter('.prompt pre')->each(static fn ($pre) => $pre->text(normalizeWhitespace: false)));
    }

    public function testAnonymousVisitGoesToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/author/prompts');

        self::assertResponseRedirects('/login');
    }
}
