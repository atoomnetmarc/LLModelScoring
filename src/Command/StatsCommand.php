<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use LLMScoring\Report\CliReporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to display evaluation statistics
 */
class StatsCommand extends Command
{
    public function __construct()
    {
        parent::__construct('stats');
        $this->setDescription('Display evaluation statistics');
        $this->setHelp('This command shows statistics about model evaluations.');
    }

    protected function configure(): void
    {
        $this->addOption(
            'json',
            'j',
            InputOption::VALUE_NONE,
            'Output as JSON'
        );
        $this->addOption(
            'detailed',
            null,
            InputOption::VALUE_NONE,
            'Show detailed statistics'
        );
        $this->addOption(
            'experiment-code',
            'e',
            InputOption::VALUE_OPTIONAL,
            'Experiment code for organizing results (default: default)',
            'default'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json');
        $detailed = $input->getOption('detailed');
        $experimentCode = $input->getOption('experiment-code') ?? 'default';

        $reporter = new CliReporter(null, $experimentCode);
        $reportData = $reporter->generateReport();

        if ($reportData['total_models'] === 0) {
            $output->writeln('<info>No models have been tested yet.</info>');
            $output->writeln('');
            $output->writeln('Run <comment>php llm-scoring.php test --from-csv</comment> to test models.');
            return Command::SUCCESS;
        }

        $stats = $reportData['statistics'];

        if ($json) {
            return $this->outputJson($output, $stats);
        }

        $this->displayStats($output, $stats, $detailed, $experimentCode);

        return Command::SUCCESS;
    }

    /**
     * Display statistics in CLI format
     */
    private function displayStats(OutputInterface $output, array $stats, bool $verbose, string $experimentCode): void
    {
        $output->writeln('<info>═══════════════════════════════════════════════════════════════</info>');
        $output->writeln('<info>                    Evaluation Statistics</info>');
        $output->writeln('<info>═══════════════════════════════════════════════════════════════</info>');
        $output->writeln('');

        // Overview section
        $output->writeln('<comment>Overview</comment>');
        $output->writeln(str_repeat('-', 60));
        $output->writeln("  Total Models Tested:      <info>{$stats['total_models']}</info>");
        $output->writeln("  Models Evaluated:         <info>{$stats['evaluated_count']}</info>");
        $output->writeln("  Pending Evaluation:       <info>" . ($stats['total_models'] - $stats['evaluated_count']) . "</info>");
        $output->writeln('');

        // Score statistics
        if ($stats['evaluated_count'] > 0) {
            $output->writeln('<comment>Score Statistics</comment>');
            $output->writeln(str_repeat('-', 60));
            $output->writeln("  Average Score:            <info>" . number_format($stats['average_score'], 1) . "%</info>");
            $output->writeln("  Highest Score:            <info>" . number_format($stats['highest_score'], 1) . "%</info>");
            $output->writeln("  Lowest Score:             <info>" . number_format($stats['lowest_score'], 1) . "%</info>");
            $output->writeln("  Score Std Deviation:      <info>" . number_format($this->calculateStdDev($stats), 2) . "</info>");
            $output->writeln('');

            // Criterion breakdown
            $output->writeln('<comment>Criterion Breakdown</comment>');
            $output->writeln(str_repeat('-', 60));

            $table = new Table($output);
            $table->setHeaders(['Criterion', 'Average Score', 'Weight']);

            $table->addRow([
                'Logic',
                '<info>' . number_format($stats['avg_logic'], 1) . '%</info>',
                '40%'
            ]);
            $table->addRow([
                'Syntax',
                '<info>' . number_format($stats['avg_syntax'], 1) . '%</info>',
                '30%'
            ]);
            $table->addRow([
                'Output',
                '<info>' . number_format($stats['avg_output'], 1) . '%</info>',
                '30%'
            ]);

            $table->render();
            $output->writeln('');
        }

        // Token and cost statistics
        $output->writeln('<comment>Resource Usage</comment>');
        $output->writeln(str_repeat('-', 60));
        $output->writeln("  Total Tokens:             <info>" . number_format($stats['total_tokens']) . "</info>");
        $output->writeln("  Total Cost:               <info>\$" . number_format($stats['total_cost'], 4) . "</info>");

        if ($stats['total_tokens'] > 0) {
            $avgCostPer1k = ($stats['total_cost'] / $stats['total_tokens']) * 1000;
            $output->writeln("  Avg Cost/1K Tokens:       <info>\$" . number_format($avgCostPer1k, 4) . "</info>");
        } else {
            $output->writeln("  Avg Cost/1K Tokens:       <info>\$0.00</info>");
        }
        $output->writeln('');

        // Score distribution (if verbose)
        if ($verbose && $stats['evaluated_count'] > 0) {
            $this->displayScoreDistribution($output, $stats, $experimentCode);
        }

        $output->writeln("<comment>Generated at: " . date('c') . "</comment>");
    }

    /**
     * Display score distribution
     */
    private function displayScoreDistribution(OutputInterface $output, array $stats, string $experimentCode): void
    {
        $output->writeln('<comment>Score Distribution</comment>');
        $output->writeln(str_repeat('-', 60));

        $reporter = new CliReporter(null, $experimentCode);
        $reportData = $reporter->generateReport();

        $excellent = 0;
        $good = 0;
        $fair = 0;
        $poor = 0;

        foreach ($reportData['models'] as $model) {
            if ($model['overall_score'] >= 80) {
                $excellent++;
            } elseif ($model['overall_score'] >= 60) {
                $good++;
            } elseif ($model['overall_score'] >= 40) {
                $fair++;
            } else {
                $poor++;
            }
        }

        $total = $excellent + $good + $fair + $poor;

        if ($total > 0) {
            $output->writeln("  Excellent (80%+):  <info>" . str_repeat('█', (int)($excellent / $total * 20)) . "</info> {$excellent}");
            $output->writeln("  Good (60-79%):     <info>" . str_repeat('█', (int)($good / $total * 20)) . "</info> {$good}");
            $output->writeln("  Fair (40-59%):     <info>" . str_repeat('█', (int)($fair / $total * 20)) . "</info> {$fair}");
            $output->writeln("  Poor (<40%):       <info>" . str_repeat('█', (int)($poor / $total * 20)) . "</info> {$poor}");
        }

        $output->writeln('');
    }

    /**
     * Output statistics as JSON
     */
    private function outputJson(OutputInterface $output, array $stats): int
    {
        $reporter = new CliReporter();
        $reportData = $reporter->generateReport();

        $json = [
            'generated_at' => $reportData['generated_at'],
            'total_models' => $stats['total_models'],
            'evaluated_count' => $stats['evaluated_count'],
            'score_statistics' => [
                'average' => round($stats['average_score'], 2),
                'highest' => round($stats['highest_score'], 2),
                'lowest' => round($stats['lowest_score'], 2),
                'std_deviation' => round($this->calculateStdDev($stats), 2),
            ],
            'criterion_breakdown' => [
                'logic' => round($stats['avg_logic'], 2),
                'syntax' => round($stats['avg_syntax'], 2),
                'output' => round($stats['avg_output'], 2),
            ],
            'resource_usage' => [
                'total_tokens' => $stats['total_tokens'],
                'total_cost' => round($stats['total_cost'], 6),
                'cost_per_1k_tokens' => $stats['total_tokens'] > 0
                    ? round(($stats['total_cost'] / $stats['total_tokens']) * 1000, 4)
                    : 0,
            ],
        ];

        $output->writeln(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * Calculate standard deviation of scores
     */
    private function calculateStdDev(array $stats): float
    {
        if ($stats['evaluated_count'] < 2) {
            return 0;
        }

        $reporter = new CliReporter();
        $reportData = $reporter->generateReport();

        $scores = array_map(fn($m) => $m['overall_score'], $reportData['models']);
        $scores = array_filter($scores, fn($s) => $s > 0);

        if (count($scores) < 2) {
            return 0;
        }

        $mean = array_sum($scores) / count($scores);
        $variance = array_reduce($scores, fn($carry, $score) => $carry + pow($score - $mean, 2), 0) / count($scores);

        return sqrt($variance);
    }
}
