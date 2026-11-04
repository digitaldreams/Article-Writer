<?php

namespace App\Eval;

use App\Planning\FindRelated\CandidateRetriever;
use App\Planning\FindRelated\FindRelated;
use App\Planning\FindRelated\JudgeRelated;
use App\Planning\FindRelated\RelatedLink;
use App\Shared\Llm\LlmCost;
use Symfony\AI\Platform\Event\ResultConvertedEvent;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The real related agent on evals/datasets/related: for each query article, did the links include the expected ones?
 * Costs tokens. Titles only, all in one call (the lazy way).
 */
#[When(env: 'dev')]
#[AsCommand('app:eval:related', 'Measures FindRelated on the related-articles dataset (real model, costs tokens)')]
final readonly class RelatedEvalCommand
{
    private const MIN_RECALL = 0.8;

    public function __construct(
        private FindRelated $finder,
        private CandidateRetriever $retriever,
        private JudgeRelated $judge,
        #[Autowire(service: 'app.find_related_tools')]
        private FindRelated $finderWithTools,
        private LlmCost $cost,
        private EventDispatcherInterface $dispatcher,
        #[Autowire(param: 'app.ai.primary_platform')]
        private string $platform,
        #[Autowire(env: 'AI_PRIMARY_MODEL')]
        private string $model,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Runs per query; the model answers differently each time')] int $runs = 3,
        #[Option('Dataset folder under evals/datasets/')] string $dataset = 'related',
        #[Option('Let the model call read_article (subtitle + headings) on candidates')] bool $tools = false,
        #[Option('Sub-agents: one separate model call per candidate (needs --top)')] bool $judge = false,
        #[Option('Send only the K best candidates by word overlap (0 = all)')] int $top = 0,
        #[Option('Extra made-up distractor titles added to every candidate list (grows the haystack)')] int $pad = 0,
    ): int {
        if ($judge && $top < 1) {
            $io->error('--judge needs --top=K: one call per candidate gets expensive fast.');

            return Command::INVALID;
        }
        $dir = "{$this->projectDir}/evals/datasets/$dataset";
        $corpus = array_column(json_decode((string) file_get_contents("$dir/corpus.json"), true, flags: \JSON_THROW_ON_ERROR), null, 'id');
        $cases = json_decode((string) file_get_contents("$dir/cases.json"), true, flags: \JSON_THROW_ON_ERROR)['cases'];
        $provider = substr($this->platform, \strlen('ai.platform.'));
        $io->title("related on $provider/{$this->model}");

        $tokens = [];
        $calls = 0;
        $steps = [];
        $this->dispatcher->addListener(ResultConvertedEvent::class, static function (ResultConvertedEvent $event) use (&$tokens, &$calls, &$steps): void {
            ++$calls;
            $usage = $event->getResult()->getMetadata()->get('token_usage');
            if ($usage instanceof TokenUsageInterface) {
                $m = $event->getModel()->getName();
                $steps[array_key_last($steps) ?? 0][] = [(int) $usage->getPromptTokens(), (int) $usage->getCachedTokens()];
                $tokens[$m] = [($tokens[$m][0] ?? 0) + (int) $usage->getPromptTokens(), ($tokens[$m][1] ?? 0) + (int) $usage->getCompletionTokens(), ($tokens[$m][2] ?? 0) + (int) $usage->getCachedTokens()];
            }
        });

        [$planted, $extras, $log, $retrieved] = [[], [], [], []];
        $start = microtime(true);
        foreach ($cases as $case) {
            $query = $corpus[$case['query']];
            $candidates = array_values(array_map(static fn (array $a) => ['id' => $a['id'], 'title' => $a['title'], 'subtitle' => $a['subtitle'], 'headings' => $a['headings']], array_filter($corpus, static fn (array $a) => $a['id'] !== $query['id'])));
            $candidates = [...$candidates, ...self::distractors($pad)];
            if ($top > 0) {
                $candidates = $this->retriever->top($query['title'], $query['subtitle'], $query['headings'], $candidates, $top);
                foreach ($case['expected'] as $expected) {
                    $retrieved["{$query['id']}>$expected"] = \in_array($expected, array_column($candidates, 'id'), true);
                }
            }
            for ($run = 0; $run < $runs; ++$run) {
                $steps[] = []; // a new ask starts: its model calls are its steps
                $ids = array_map(static fn (RelatedLink $l) => $l->id, ($judge ? $this->judge : ($tools ? $this->finderWithTools : $this->finder))->ask($query['title'], $query['subtitle'], $query['headings'], $candidates));
                $log[$query['id']][] = $ids;
                foreach ($case['expected'] as $expected) {
                    $planted["{$query['id']}>$expected"][] = \in_array($expected, $ids, true) ? Score::FOUND : Score::MISSED;
                }
                $extras["{$query['id']}"][] = \count(array_diff($ids, $case['expected']));
                $io->write('.');
            }
        }
        $io->newLine(2);

        if ($top > 0) {
            $io->writeln(\sprintf('retriever: %d of %d expected articles are in the top %d (%s)', \count(array_filter($retrieved)), \count($retrieved), $top, implode(' ', array_map(static fn (string $c, bool $h) => $c.($h ? '' : ' OUT'), array_keys($retrieved), $retrieved))));
        }
        if (0 === $runs) {
            return Command::SUCCESS;
        }
        $score = new Score($planted, []);
        $io->table(['query>expected', 'runs'], array_map(static fn (string $c, array $r) => [$c, implode(' | ', $r)], array_keys($planted), $planted));
        $cost = 0.0;
        foreach ($tokens as $m => [$in, $out]) {
            $cost += $this->cost->of($provider, $m, $in, $out) ?? 0.0;
        }
        [$low, $high] = $score->interval();
        $allExtras = array_merge(...array_values($extras));
        $io->listing(array_filter([
            \sprintf('recall %.2f, interval %.2f–%.2f (%d expected links × %d runs, %d queries)', $score->recall(), $low, $high, \count($planted), $runs, \count($cases)),
            \sprintf('links to unlabeled articles: %.2f per query (not wrong by itself: the labels are short)', array_sum($allExtras) / max(1, \count($allExtras))),
            $score->flaky() ? 'flaky: '.implode(', ', $score->flaky()) : null,
            $score->alwaysMissed() ? 'always missed: '.implode(', ', $score->alwaysMissed()) : null,
            \sprintf('%d model calls, %s, $%.4f, %ds', $calls, json_encode($tokens), $cost, microtime(true) - $start),
        ]));

        $file = \sprintf('%s/var/evals/%s-%s-%s.json', $this->projectDir, $dataset, date('Ymd-His'), $this->model);
        @mkdir(\dirname($file), recursive: true);
        file_put_contents($file, json_encode([
            'platform' => $provider, 'model' => $this->model, 'dataset' => $dataset, 'runs' => $runs, 'candidates' => \count($corpus) - 1 + $pad, 'pad' => $pad, 'tools' => $tools, 'judge' => $judge, 'top' => $top, 'retrieved' => $retrieved, 'model_calls' => $calls, 'steps' => $steps,
            'recall' => $score->recall(), 'interval' => [$low, $high], 'extras' => $extras, 'planted' => $planted, 'links' => $log, 'tokens' => $tokens, 'cost' => $cost,
        ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        $io->comment('Saved '.substr($file, \strlen($this->projectDir) + 1));

        if ($score->recall() < self::MIN_RECALL) {
            $io->error(\sprintf('Below the floor: recall ≥ %.2f.', self::MIN_RECALL));

            return Command::FAILURE;
        }
        $io->success('Within the floor.');

        return Command::SUCCESS;
    }

    /**
     * Made-up titles on topics unrelated to the corpus, ids from 1000. Deterministic, so runs are comparable.
     *
     * @return list<array{id: int, title: string}>
     */
    private static function distractors(int $n): array
    {
        $subjects = ['Kubernetes', 'PostgreSQL indexes', 'Rust lifetimes', 'CSS grid', 'Terraform modules', 'React hooks', 'Redis streams', 'GraphQL schemas', 'Docker layers', 'Nginx caching', 'Go channels', 'Kafka partitions', 'Vue stores', 'SQLite WAL', 'Elasticsearch mappings', 'OAuth scopes', 'WebSocket scaling', 'Ansible roles', 'Tailwind themes', 'Prometheus alerts'];
        $angles = ['A Practical Guide to', 'Common Mistakes with', 'Mastering', 'Debugging', 'Testing', 'Scaling', 'Migrating to', 'Securing'];
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $out[] = ['id' => 1000 + $i, 'title' => $angles[intdiv($i, \count($subjects)) % \count($angles)].' '.$subjects[$i % \count($subjects)].($i >= \count($angles) * \count($subjects) ? ', Part '.(intdiv($i, \count($angles) * \count($subjects)) + 1) : '')];
        }

        return $out;
    }
}
