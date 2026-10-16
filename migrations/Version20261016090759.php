<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016090759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Poster ideas remember the writer idea they were built on.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE poster_idea ADD writer_idea TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE poster_idea DROP writer_idea');
    }
}
