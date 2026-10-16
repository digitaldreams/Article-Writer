<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016080427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Title suggestions get a subtitle.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Title-only suggestions from before subtitles are dropped; a new round brings pairs.
        $this->addSql('DELETE FROM title_suggestion');
        $this->addSql('ALTER TABLE title_suggestion ADD subtitle VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE title_suggestion DROP subtitle');
    }
}
