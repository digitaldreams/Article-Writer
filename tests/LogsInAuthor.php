<?php

namespace App\Tests;

use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Every page needs a logged-in author, and every row needs its user. The test transaction rolls both back. */
trait LogsInAuthor
{
    /** The logged-in author; rows a test makes for them must use this user, or the owner filter hides them. */
    protected User $author;

    /** Onboarded by default: only the onboarding tests need an author still in the wizard. */
    protected static function newAuthor(?string $email = null, bool $onboarded = true): User
    {
        $author = new User($email ?? 'author-'.bin2hex(random_bytes(4)).'@example.com');
        if ($onboarded) {
            $author->markOnboarded();
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($author);
        $em->flush();

        return $author;
    }

    protected function logIn(KernelBrowser $client, bool $onboarded = true): User
    {
        $this->author = self::newAuthor(onboarded: $onboarded);
        $client->loginUser($this->author);

        return $this->author;
    }
}
