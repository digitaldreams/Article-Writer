<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove image references: delete image reference rows and describe_image jobs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM reference WHERE type = 'image'");
        $this->addSql("DELETE FROM llm_job WHERE step = 'describe_image'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Deleted image references cannot be restored.');
    }
}
