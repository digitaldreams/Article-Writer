<?php

namespace App\Tests\Shared\User\Register;

use App\Shared\User\User;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegisterTest extends WebTestCase
{
    use LogsInAuthor;

    public function testAnonymousVisitorCanOpenRegister(): void
    {
        static::createClient()->request('GET', '/register');

        self::assertResponseIsSuccessful();
    }

    public function testRegisteringCreatesAUserWithAHashedPasswordAndLogsThemIn(): void
    {
        $client = static::createClient();

        $this->register($client, 'new-author@example.com', 'secret', 'secret');

        self::assertResponseRedirects('/plans');
        $user = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'new-author@example.com']);
        self::assertNotNull($user);
        self::assertNotSame('secret', $user->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'secret'));
        self::assertFalse($user->isOnboarded());
        $client->request('GET', '/welcome');
        self::assertSelectorTextContains('.topbar-user', 'new-author@example.com');
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $client = static::createClient();
        $taken = self::newAuthor()->getEmail();

        $this->register($client, $taken, 'secret', 'secret');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'This email already has an account.');
    }

    public function testPasswordMismatchIsRejected(): void
    {
        $client = static::createClient();

        $this->register($client, 'mismatch@example.com', 'secret', 'other');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'The passwords do not match.');
    }

    public function testExistingAuthorsAreOnboarded(): void
    {
        $unonboarded = (int) self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM "user" WHERE onboarded_at IS NULL');

        // Rows made before the migration got NOW(); the test transaction holds only rows from this test.
        self::assertSame(0, $unonboarded);
        self::assertTrue(self::newAuthor()->isOnboarded());
    }

    private function register(KernelBrowser $client, string $email, string $password, string $repeat): void
    {
        $crawler = $client->request('GET', '/register');
        $client->submit($crawler->selectButton('Create account')->form([
            'register[email]' => $email,
            'register[password][first]' => $password,
            'register[password][second]' => $repeat,
        ]));
    }
}
