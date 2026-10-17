<?php

namespace App\Tests\Shared\User;

use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    use LogsInAuthor;

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        static::createClient()->request('GET', '/plans');

        self::assertResponseRedirects('/login');
    }

    public function testAuthorLogsInWithTheRightPasswordAndLandsOnPlans(): void
    {
        $client = static::createClient();
        $email = $this->authorWithPassword('secret');

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Log in')->form(['_username' => $email, '_password' => 'secret']));

        self::assertResponseRedirects('/plans');
        $client->followRedirect();
        self::assertSelectorTextContains('.topbar-user', $email);
    }

    public function testWrongPasswordShowsTheLoginFormWithAnError(): void
    {
        $client = static::createClient();
        $email = $this->authorWithPassword('secret');

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Log in')->form(['_username' => $email, '_password' => 'wrong']));

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Invalid credentials');
    }

    private function authorWithPassword(string $password): string
    {
        $author = self::newAuthor();
        $author->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($author, $password));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $author->getEmail();
    }
}
