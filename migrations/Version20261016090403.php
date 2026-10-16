<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016090403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Article folders are named by the article id; the folder column goes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP folder');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD folder VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE article SET folder = id::text');
        $this->addSql('ALTER TABLE article ALTER folder SET NOT NULL');
    }
}
