<?php

namespace App\Planning\ApprovePlan;

use App\Planning\OutlineItem;
use App\Planning\PlanInput;
use App\Planning\Question;
use App\Planning\Reference;
use App\Shared\Article\Article;
use App\Shared\Storage\ArticleFolder;

/** Writes idea.md, outline.md and (with at least one answer) answers.md into the article folder. */
final readonly class PlanFiles
{
    public function __construct(private ArticleFolder $folder)
    {
    }

    /**
     * @param list<Reference>   $references
     * @param list<OutlineItem> $items
     * @param list<Question>    $questions
     */
    public function write(Article $article, PlanInput $input, array $references, array $items, array $questions, \DateTimeImmutable $now): void
    {
        $id = (int) $article->getId();

        $this->folder->write($article->getFolder(), 'idea.md', ['plan_id' => $id, 'created_at' => $article->getCreatedAt()->format(\DATE_ATOM)], $this->idea($input, $references));
        $this->folder->write($article->getFolder(), 'outline.md', ['plan_id' => $id, 'title' => $article->getTitle(), 'approved_at' => $now->format(\DATE_ATOM)], $this->outline($items));

        $answered = array_values(array_filter($questions, static fn (Question $q) => !$q->isSkipped() && null !== $q->getAnswer()));
        if ([] !== $answered) {
            $this->folder->write($article->getFolder(), 'answers.md', ['plan_id' => $id, 'answered' => \count($answered), 'total' => \count($questions)], $this->answers($answered));
        }
    }

    /** @param list<Reference> $references */
    private function idea(PlanInput $input, array $references): string
    {
        $body = "# {$input->getTopic()}\n\n"
            ."**Audience:** {$input->getAudience()}\n\n"
            .'**Reader level:** '.($input->getReaderLevel() ?? '')."\n\n"
            ."## Rough flow\n\n{$input->getRoughFlow()}\n";

        if ([] !== $references) {
            $body .= "\n## References\n\n";
            foreach ($references as $reference) {
                $body .= '- '.$reference->getLocation()."\n";
            }
        }

        return $body;
    }

    /** @param list<OutlineItem> $items */
    private function outline(array $items): string
    {
        return implode("\n", array_map(static fn (OutlineItem $item) => "## {$item->getTitle()}\n\n{$item->getSummary()}\n", $items));
    }

    /** @param list<Question> $answered */
    private function answers(array $answered): string
    {
        return implode("\n", array_map(static fn (Question $q) => '## '.$q->getText()."\n\n".$q->getAnswer()."\n", $answered));
    }
}
