<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016130237 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Poster ideas keep a rough SVG sketch.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE poster_idea ADD sketch TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE poster_idea DROP sketch');
    }
}
