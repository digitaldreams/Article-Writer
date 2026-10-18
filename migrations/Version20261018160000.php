<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Writing note: structure answers are TEXT, like the voice answers (a long model answer no longer fails the job).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE writing_note ALTER opening_type TYPE TEXT');
        $this->addSql('ALTER TABLE writing_note ALTER ordering_pattern TYPE TEXT');
        $this->addSql('ALTER TABLE writing_note ALTER closing TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE writing_note ALTER opening_type TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE writing_note ALTER ordering_pattern TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE writing_note ALTER closing TYPE VARCHAR(255)');
    }
}
