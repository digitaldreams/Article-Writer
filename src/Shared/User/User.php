<?php

namespace App\Shared\User;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** An author. Every other row carries its user_id; OwnerFilter hides the rows of other authors. */
#[ORM\Entity]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $shortBio = '';

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $longBio = '';

    /** The English level every prompt writes for; empty before the bio is saved. */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $languageLevel = '';

    /** @var array{structure: array<string, string>, voice: array<string, string>}|null structure feeds the outline; voice feeds the article and rewrites */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $writingProfile = null;

    /** Set when the author approves the writing profile at the end of the onboarding wizard; null keeps them in the wizard. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $onboardedAt = null;

    public function __construct(
        #[ORM\Column(length: 180, unique: true)]
        private string $email,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShortBio(): string
    {
        return $this->shortBio;
    }

    public function getLongBio(): string
    {
        return $this->longBio;
    }

    public function getLanguageLevel(): string
    {
        return $this->languageLevel;
    }

    public function updateProfile(string $shortBio, string $longBio, string $languageLevel): void
    {
        $this->shortBio = $shortBio;
        $this->longBio = $longBio;
        $this->languageLevel = $languageLevel;
    }

    /** @return array{structure: array<string, string>, voice: array<string, string>} empty arrays before the first analysis */
    public function getWritingProfile(): array
    {
        return $this->writingProfile ?? ['structure' => [], 'voice' => []];
    }

    /**
     * @param array<string, string> $structure
     * @param array<string, string> $voice
     */
    public function setWritingProfile(array $structure, array $voice): void
    {
        $this->writingProfile = ['structure' => $structure, 'voice' => $voice];
    }

    public function isOnboarded(): bool
    {
        return null !== $this->onboardedAt;
    }

    public function markOnboarded(): void
    {
        $this->onboardedAt ??= new \DateTimeImmutable();
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** ponytail: one role, every user is an author. Add a roles column when a second role exists. */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }
}
