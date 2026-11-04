<?php

namespace App\Eval;

use App\Planning\ArticleSections;
use App\Planning\FinalCheck\CheckArticleHandler;
use App\Planning\FinalCheck\CheckReport;
use App\Shared\Storage\ArticleFolder;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Twig\Environment;

/** Shows what one final_check call reads, part by part. No model call, no tokens spent. */
#[When(env: 'dev')]
#[AsCommand('app:context:show', 'Shows the parts of one final_check request with sizes (no model call)')]
final readonly class ShowContextCommand
{
    // ponytail: same level as app:eval:final-check; only its length matters here
    private const LANGUAGE_LEVEL = 'B1';

    public function __construct(
        private Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Case folder, e.g. 01-code-typo')] string $case,
        #[Option('Folder under evals/datasets/')] string $dataset = 'final_check',
        #[Option('Print the rendered user message too')] bool $full = false,
    ): int {
        $dir = "{$this->projectDir}/evals/datasets/$dataset/$case";
        if (!is_dir($dir)) {
            $io->error("No case at evals/datasets/$dataset/$case.");

            return Command::FAILURE;
        }

        $idea = self::read($dir, 'idea.md');
        $outline = CheckArticleHandler::outlineHeadings(self::read($dir, 'outline.md'));
        $body = self::read($dir, 'article.md') ?? '';
        $headings = array_keys(ArticleSections::split($body));
        $request = $this->twig->render('planning/final_check/_check_request.md.twig', compact('idea', 'outline', 'headings', 'body'));

        $headingsText = implode('', array_map(static fn (string $h) => "- $h\n", $headings));
        $parts = [
            'system prompt' => strtr((string) file_get_contents($this->projectDir.'/prompts/final_check_agent.md'), ['{max}' => (string) CheckArticleHandler::MAX_NOTES, '{language_level}' => self::LANGUAGE_LEVEL]),
            'answer schema' => json_encode((new ResponseFormatFactory())->create(CheckReport::class), \JSON_THROW_ON_ERROR),
            'idea.md' => $idea ?? '',
            'outline.md' => $outline ?? '',
            'headings list' => $headingsText,
            'article' => $body,
        ];
        // What is left of the rendered message is the template's own words: the labels between the parts.
        $parts['template frame'] = str_repeat('x', max(0, mb_strlen($request) - mb_strlen(($idea ?? '').($outline ?? '').$headingsText.$body)));

        $budget = new ContextBudget($parts);
        $io->title("final_check request for $dataset/$case");
        $io->table(['part', 'chars', '~tokens', 'share'], array_map(
            static fn (string $part, int $chars) => [$part, number_format($chars), number_format($budget->tokens($part)), \sprintf('%.0f%%', 100 * $budget->share($part))],
            array_keys($budget->parts()),
            $budget->parts(),
        ));
        $io->writeln(\sprintf('total %s chars, ~%s input tokens (estimate, %.1f chars per token)', number_format($budget->total()), number_format((int) round($budget->total() / ContextBudget::CHARS_PER_TOKEN)), ContextBudget::CHARS_PER_TOKEN));
        $io->writeln(\sprintf('your prompt (system prompt) is %.0f%% of what the model reads', 100 * $budget->share('system prompt')));

        if ($full) {
            $io->section('user message');
            $io->writeln($request);
        }

        return Command::SUCCESS;
    }

    /** The file without its front matter, read the way the job reads it; null when the case has no such file. */
    private static function read(string $dir, string $file): ?string
    {
        return is_file("$dir/$file") ? (new ArticleFolder(\dirname($dir)))->read(basename($dir), $file)['body'] : null;
    }
}
