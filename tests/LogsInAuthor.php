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

    protected static function newAuthor(?string $email = null): User
    {
        $author = new User($email ?? 'author-'.bin2hex(random_bytes(4)).'@example.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($author);
        $em->flush();

        return $author;
    }

    protected function logIn(KernelBrowser $client): User
    {
        $this->author = self::newAuthor();
        $client->loginUser($this->author);

        return $this->author;
    }
}
