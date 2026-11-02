<?php

namespace App\Eval;

use App\Planning\FinalCheck\CheckFinding;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The judge against your own labels in evals/labels/judge_calibration.json. Collect them from the "judged" list of an
 * app:eval:final-check --judge result, add "human": true|false to each (decide before you read the judge's reason).
 * Tune the rubric on judge_calibration.json; run --labels=judge_holdout once at the end, those are the rates you report.
 */
#[When(env: 'dev')]
#[AsCommand('app:eval:calibrate', 'Checks the LLM judge against your own pass/fail labels (real model, costs tokens)')]
final readonly class CalibrateJudgeCommand
{
    // Both rates, not plain agreement: with 27 of 30 labels "pass", a judge that always passes agrees 90%.
    private const MIN_RATE = 0.85;

    public function __construct(
        private Judge $judge,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Labels file under evals/labels/, without .json')] string $labels = 'judge_calibration',
    ): int {
        $file = $this->projectDir.'/evals/labels/'.basename($labels).'.json';
        if (!is_file($file)) {
            $io->error("No labels yet: evals/labels/$labels.json, a list of {\"planted\", \"notes\": [{\"section\", \"problem\"}], \"human\"}.");

            return Command::FAILURE;
        }

        $labels = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        $io->title(\sprintf('judge %s/%s on %d labels', Judge::PLATFORM, Judge::MODEL, \count($labels)));
        $boxes = ['tp' => 0, 'fn' => 0, 'tn' => 0, 'fp' => 0];
        $disagree = [];
        foreach ($labels as $i => $label) {
            $notes = array_map(static fn (array $n) => new CheckFinding($n['section'], $n['problem'], ''), $label['notes']);
            $verdict = $this->judge->notesDescribe($label['planted'], $notes);
            ++$boxes[$label['human'] ? ($verdict->pass ? 'tp' : 'fn') : ($verdict->pass ? 'fp' : 'tn')];
            if ($verdict->pass !== $label['human']) {
                $disagree[] = \sprintf('#%d you %s, judge %s: %s', $i, $label['human'] ? 'pass' : 'fail', $verdict->pass ? 'pass' : 'fail', $verdict->reason);
            }
            $io->write('.');
        }
        $io->newLine(2);

        $onPasses = $boxes['tp'] / max(1, $boxes['tp'] + $boxes['fn']);
        $onFails = $boxes['tn'] / max(1, $boxes['tn'] + $boxes['fp']);
        $io->listing([
            \sprintf('judge on your passes %.2f (%d of %d)', $onPasses, $boxes['tp'], $boxes['tp'] + $boxes['fn']),
            \sprintf('judge on your fails  %.2f (%d of %d)', $onFails, $boxes['tn'], $boxes['tn'] + $boxes['fp']),
        ]);
        if ($disagree) {
            $io->section('Disagreements: fix the rubric, not your labels');
            $io->listing($disagree);
        }

        if ($onPasses < self::MIN_RATE || $onFails < self::MIN_RATE) {
            $io->error(\sprintf('Not calibrated: both rates must be ≥ %.2f.', self::MIN_RATE));

            return Command::FAILURE;
        }
        $io->success('Calibrated.');

        return Command::SUCCESS;
    }
}
