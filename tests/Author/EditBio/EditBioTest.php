<?php

namespace App\Tests\Author\EditBio;

use App\Shared\User\User;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditBioTest extends WebTestCase
{
    use LogsInAuthor;

    public function testSavedValuesShowAgainOnNextVisit(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->request('GET', '/author');
        self::assertResponseIsSuccessful();

        $client->submitForm('Save', [
            'edit_bio[shortBio]' => 'Short me',
            'edit_bio[longBio]' => 'Long me',
            'edit_bio[languageLevel]' => 'English, second language, intermediate',
        ]);
        self::assertResponseRedirects('/author');

        $crawler = $client->request('GET', '/author');
        $form = $crawler->selectButton('Save')->form();
        self::assertSame('Short me', $form['edit_bio[shortBio]']->getValue());
        self::assertSame('Long me', $form['edit_bio[longBio]']->getValue());
        self::assertSame('English, second language, intermediate', $form['edit_bio[languageLevel]']->getValue());
    }

    public function testSavingAgainUpdatesTheSingleProfile(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        foreach (['First', 'Second'] as $bio) {
            $client->request('GET', '/author');
            $client->submitForm('Save', [
                'edit_bio[shortBio]' => $bio,
                'edit_bio[longBio]' => 'Long',
                'edit_bio[languageLevel]' => 'Level',
            ]);
        }

        $crawler = $client->request('GET', '/author');
        self::assertSame('Second', $crawler->selectButton('Save')->form()['edit_bio[shortBio]']->getValue());
    }

    public function testEmptyBiosAreRejectedWith422(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->request('GET', '/author');
        $client->submitForm('Save', [
            'edit_bio[shortBio]' => '',
            'edit_bio[longBio]' => '',
            'edit_bio[languageLevel]' => 'Level',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('', static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->author->getId())?->getLanguageLevel());
    }

    public function testOldSetupUrlIsGone(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->request('GET', '/setup');

        self::assertResponseStatusCodeSame(404);
    }
}
