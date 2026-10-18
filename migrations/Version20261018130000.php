<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Library: article.source_url (unique per author) and published_at';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article ADD source_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE article ADD published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_23A0E66A76ED395A58240EF ON article (user_id, source_url)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_23A0E66A76ED395A58240EF');
        $this->addSql('ALTER TABLE article DROP source_url');
        $this->addSql('ALTER TABLE article DROP published_at');
    }
}
