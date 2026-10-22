<?php

namespace App\Tests\Author\EditWritingProfile;

use App\Shared\User\User;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditWritingProfileTest extends WebTestCase
{
    use LogsInAuthor;

    public function testTheHandEditedProfileSaves(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $crawler = $client->request('GET', '/author/profile');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Save')->form([
            'edit_writing_profile[openingType]' => 'a question',
            'edit_writing_profile[orderingHabit]' => 'simple to advanced',
            'edit_writing_profile[sectionCount]' => '6',
            'edit_writing_profile[runningExample]' => 'yes',
            'edit_writing_profile[closing]' => 'next steps',
        ]));

        self::assertResponseRedirects('/author/profile');
        $profile = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->author->getId());
        self::assertSame('a question', $profile?->getWritingProfile()['structure']['openingType'] ?? null);
        self::assertSame('next steps', $profile->getWritingProfile()['structure']['closing']);
        // Voice may stay blank.
        self::assertSame('', $profile->getWritingProfile()['voice']['tone']);
    }

    public function testTheVoiceSavesNextToTheStructure(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $crawler = $client->request('GET', '/author/profile');

        $client->submit($crawler->selectButton('Save')->form([
            'edit_writing_profile[openingType]' => 'a question',
            'edit_writing_profile[orderingHabit]' => 'simple to advanced',
            'edit_writing_profile[sectionCount]' => '6',
            'edit_writing_profile[runningExample]' => 'yes',
            'edit_writing_profile[closing]' => 'next steps',
            'edit_writing_profile[tone]' => 'friendly',
            'edit_writing_profile[samples]' => "One.\n\nTwo.",
        ]));

        $profile = (self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->author->getId()) ?? throw new \LogicException('No author.'))->getWritingProfile();
        self::assertSame('friendly', $profile['voice']['tone']);
        self::assertSame("One.\n\nTwo.", $profile['voice']['samples']);
        self::assertArrayNotHasKey('tone', $profile['structure']);
    }

    public function testBlankFieldIsRejectedWith422(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $crawler = $client->request('GET', '/author/profile');

        $client->submit($crawler->selectButton('Save')->form());

        self::assertResponseStatusCodeSame(422);
    }
}
