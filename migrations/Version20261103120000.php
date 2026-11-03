<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103120000 extends AbstractMigration
{
    private const MOVED = [
        'App\Planning\DigestReferences\ExtractReference' => 'App\Planning\References\ExtractReference',
        'App\Planning\DigestReferences\DigestReference' => 'App\Planning\References\DigestReference',
        'App\Planning\AskQuestions\AskQuestions' => 'App\Planning\Questions\AskQuestions',
        'App\Planning\GenerateOutline\GenerateOutline' => 'App\Planning\Outline\GenerateOutline',
        'App\Planning\WriteArticle\WriteArticle' => 'App\Planning\Article\WriteArticle',
        'App\Planning\RewriteSection\RewriteSection' => 'App\Planning\Article\RewriteSection',
        'App\Planning\SuggestTitles\SuggestTitles' => 'App\Planning\Titles\SuggestTitles',
        'App\Planning\SuggestPunchlines\SuggestPunchlines' => 'App\Planning\Share\SuggestPunchlines',
        'App\Planning\SuggestPosters\SuggestPosters' => 'App\Planning\Poster\SuggestPosters',
        'App\Planning\SuggestPosters\GeneratePoster' => 'App\Planning\Poster\GeneratePoster',
    ];

    public function getDescription(): string
    {
        return 'llm_job.message: the Planning job messages moved to new namespaces, so saved messages get the new class names (Retry unserializes them).';
    }

    public function up(Schema $schema): void
    {
        $this->rename(false);
    }

    public function down(Schema $schema): void
    {
        $this->rename(true);
    }

    /** @param string $message base64 of a serialized message, as LlmJob stores it */
    public static function renamed(string $message, bool $back = false): string
    {
        $text = base64_decode($message);
        $renamed = $text;
        foreach ($back ? array_flip(self::MOVED) : self::MOVED as $from => $to) {
            // The class itself, and its private llmJobId property: PHP writes that name as "\0Class\0llmJobId".
            $renamed = str_replace(
                [self::classEntry($from), self::idEntry($from)],
                [self::classEntry($to), self::idEntry($to)],
                $renamed,
            );
        }

        return $renamed === $text ? $message : base64_encode($renamed);
    }

    private function rename(bool $back): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, message FROM llm_job WHERE message IS NOT NULL') as $job) {
            $message = self::renamed($job['message'], $back);
            if ($message !== $job['message']) {
                $this->addSql('UPDATE llm_job SET message = :message WHERE id = :id', ['message' => $message, 'id' => $job['id']]);
            }
        }
    }

    private static function classEntry(string $class): string
    {
        return \sprintf('O:%d:"%s"', \strlen($class), $class);
    }

    private static function idEntry(string $class): string
    {
        $name = "\0{$class}\0llmJobId";

        return \sprintf('s:%d:"%s"', \strlen($name), $name);
    }
}
