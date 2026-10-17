<?php

namespace App\Tests\Setup\SaveProfile;

use App\Shared\User\User;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SaveProfileTest extends WebTestCase
{
    use LogsInAuthor;

    public function testSavedValuesShowAgainOnNextVisit(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->request('GET', '/setup');
        self::assertResponseIsSuccessful();

        $client->submitForm('Save', [
            'save_profile[shortBio]' => 'Short me',
            'save_profile[longBio]' => 'Long me',
            'save_profile[languageLevel]' => 'English, second language, intermediate',
        ]);
        self::assertResponseRedirects('/setup');

        $crawler = $client->request('GET', '/setup');
        $form = $crawler->selectButton('Save')->form();
        self::assertSame('Short me', $form['save_profile[shortBio]']->getValue());
        self::assertSame('Long me', $form['save_profile[longBio]']->getValue());
        self::assertSame('English, second language, intermediate', $form['save_profile[languageLevel]']->getValue());
    }

    public function testSavingAgainUpdatesTheSingleProfile(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        foreach (['First', 'Second'] as $bio) {
            $client->request('GET', '/setup');
            $client->submitForm('Save', [
                'save_profile[shortBio]' => $bio,
                'save_profile[longBio]' => 'Long',
                'save_profile[languageLevel]' => 'Level',
            ]);
        }

        $crawler = $client->request('GET', '/setup');
        self::assertSame('Second', $crawler->selectButton('Save')->form()['save_profile[shortBio]']->getValue());
    }

    public function testEmptyBiosAreRejectedWith422(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->request('GET', '/setup');
        $client->submitForm('Save', [
            'save_profile[shortBio]' => '',
            'save_profile[longBio]' => '',
            'save_profile[languageLevel]' => 'Level',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('', static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->author->getId())?->getLanguageLevel());
    }
}
