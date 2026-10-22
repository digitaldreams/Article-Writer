<?php

namespace App\Shared\User;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** A ready author for local use (bin/console doctrine:fixtures:load); new authors register at /register. */
final class AuthorFixture extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $author = new User('author@example.com');
        $author->setPassword($this->hasher->hashPassword($author, 'secret'));
        $author->markOnboarded();
        $manager->persist($author);
        $manager->flush();
    }
}
