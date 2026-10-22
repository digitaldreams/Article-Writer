<?php

namespace Mate;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

class DbQuery
{
    #[MateTool(name: 'db-query', title: 'DB Query', description: 'Run one SQL query against the app database (DATABASE_URL) inside a READ ONLY transaction and return the rows. Writes are rejected by Postgres. Tables use snake_case (article, llm_job, outline_item, ...); every child table has article_id and user_id.')]
    public function query(string $sql, int $limit = 100): string
    {
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL']);
        $connection = DriverManager::getConnection($params);

        // ponytail: read-only enforced by Postgres, not by parsing the SQL
        $connection->executeStatement('BEGIN READ ONLY');
        try {
            $rows = $connection->fetchAllAssociative($sql);
        } finally {
            $connection->executeStatement('ROLLBACK');
        }

        return ResponseEncoder::encode([
            'rows' => \array_slice($rows, 0, $limit),
            'total_rows' => \count($rows),
            'truncated' => \count($rows) > $limit,
        ]);
    }
}
