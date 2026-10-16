<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016091733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LLM jobs record their tokens and cost.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE llm_job ADD input_tokens INT DEFAULT NULL');
        $this->addSql('ALTER TABLE llm_job ADD output_tokens INT DEFAULT NULL');
        $this->addSql('ALTER TABLE llm_job ADD cost DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE llm_job DROP input_tokens');
        $this->addSql('ALTER TABLE llm_job DROP output_tokens');
        $this->addSql('ALTER TABLE llm_job DROP cost');
    }
}
