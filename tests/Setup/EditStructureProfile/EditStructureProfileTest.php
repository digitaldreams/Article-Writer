<?php

namespace App\Tests\Setup\EditStructureProfile;

use App\Shared\User\User;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditStructureProfileTest extends WebTestCase
{
    use LogsInAuthor;

    public function testTheHandEditedProfileSaves(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $crawler = $client->request('GET', '/setup/profile');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Save')->form([
            'edit_structure_profile[openingType]' => 'a question',
            'edit_structure_profile[orderingHabit]' => 'simple to advanced',
            'edit_structure_profile[sectionCount]' => '6',
            'edit_structure_profile[runningExample]' => 'yes',
            'edit_structure_profile[closing]' => 'next steps',
        ]));

        self::assertResponseRedirects('/setup/profile');
        $profile = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->author->getId());
        self::assertSame('a question', $profile?->getStructureProfile()['openingType'] ?? null);
        self::assertSame('next steps', $profile->getStructureProfile()['closing']);
    }

    public function testBlankFieldIsRejectedWith422(): void
    {
        $client = static::createClient();
        $this->logIn($client);
        $crawler = $client->request('GET', '/setup/profile');

        $client->submit($crawler->selectButton('Save')->form());

        self::assertResponseStatusCodeSame(422);
    }
}
