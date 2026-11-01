<?php

namespace App\Eval;

use App\Planning\FinalCheck\CheckFinding;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Template;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

/** A second model grades the final check notes against the problem planted in an eval case. */
#[When(env: 'dev')]
final readonly class Judge
{
    // A different family from the checker (openai): models tend to like their own family's writing.
    public const PLATFORM = 'anthropic';
    public const MODEL = 'claude-sonnet-5';

    private const RUBRIC = <<<'MD'
        You grade the output of an article checker.
        A problem was planted in the article on purpose. You get a description of that problem and the checker's notes.

        Decide one thing: does at least one note describe the planted problem?
        - Pass: a note points to the same problem, in any words. It does not need to quote it exactly.
        - Fail: no note is about the planted problem. Notes about other real problems do not count.
        - Fail: a note is only vaguely related ("check the numbers in the article").

        Write the reason first, then the verdict.
        MD;

    public function __construct(
        #[Autowire(service: 'ai.platform.'.self::PLATFORM)]
        private PlatformInterface $platform,
    ) {
    }

    /** @param list<CheckFinding> $notes */
    public function notesDescribe(string $planted, array $notes): Verdict
    {
        // One string: an array in template_vars is flattened to checker_notes.0.section and {checker_notes} stays empty.
        $list = implode("\n", array_map(static fn (CheckFinding $n) => "- [{$n->section}] {$n->problem}", $notes)) ?: '(no notes)';

        return $this->platform->invoke(self::MODEL, new MessageBag(
            Message::forSystem(self::RUBRIC),
            Message::ofUser(Template::string("Planted problem:\n{planted_problem}\n\nChecker notes:\n{checker_notes}")),
        ), ['response_format' => Verdict::class, 'template_vars' => [
            'planted_problem' => $planted,
            'checker_notes' => $list,
        ]])->asObject();
    }
}
