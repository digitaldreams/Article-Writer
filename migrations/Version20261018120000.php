<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Setup → Author: drop setup job history and queued setup messages (their PHP-serialized class names are gone)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM llm_job WHERE step IN ('import_file', 'extract_structure_notes', 'merge_structure_profile')");
        // PhpSerializer stores addslashes(serialize()), so the class reads App\\Setup\\… in the body.
        $this->addSql(<<<'SQL'
            DELETE FROM messenger_messages WHERE position('App\\Setup\\' in body) > 0
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Deleted setup job history cannot come back.');
    }
}
