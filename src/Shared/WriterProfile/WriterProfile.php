<?php

namespace App\Shared\WriterProfile;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class WriterProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $structureProfile = null;

    public function __construct(
        #[ORM\Column(type: Types::TEXT)]
        private string $shortBio = '',
        #[ORM\Column(type: Types::TEXT)]
        private string $longBio = '',
        #[ORM\Column(type: Types::TEXT)]
        private string $languageLevel = '',
    ) {
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

    public function update(string $shortBio, string $longBio, string $languageLevel): void
    {
        $this->shortBio = $shortBio;
        $this->longBio = $longBio;
        $this->languageLevel = $languageLevel;
    }

    /** @return array<string, mixed>|null */
    public function getStructureProfile(): ?array
    {
        return $this->structureProfile;
    }

    /** @param array<string, mixed> $structureProfile */
    public function setStructureProfile(array $structureProfile): void
    {
        $this->structureProfile = $structureProfile;
    }
}
