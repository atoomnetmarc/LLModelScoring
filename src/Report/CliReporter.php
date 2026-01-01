<?php

declare(strict_types=1);

namespace LLMScoring\Report;

use LLMScoring\Storage\StorageManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CliReporter generates formatted reports for CLI output
 */
class CliReporter
{
    private StorageManager $storageManager;

    public function __construct(?StorageManager $storageManager = null, string $questionCode = 'default')
    {
        $this->storageManager = $storageManager ?? new StorageManager(null, $questionCode);
    }

    /**
     * Generate a comprehensive CLI report for all evaluated models
     */
    public function generateReport(): array
    {
        $testedModelIds = $this->storageManager->getTestedModelIds();

        $reportData = [
            'generated_at' => date('c'),
            'total_models' => count($testedModelIds),
            'models' => [],
            'statistics' => $this->calculateStatistics($testedModelIds),
        ];

        foreach ($testedModelIds as $modelId) {
            $modelData = $this->gatherModelEvaluationData($modelId);
            if ($modelData !== null) {
                $reportData['models'][] = $modelData;
            }
        }

        // Sort by overall score (highest first)
        usort($reportData['models'], fn($a, $b) => $b['overall_score'] <=> $a['overall_score']);

        return $reportData;
    }

    /**
     * Display the report in CLI format
     */
    public function displayReport(OutputInterface $output, array $reportData = null): void
    {
        $report = $reportData ?? $this->generateReport();

        $output->writeln('<info>═══════════════════════════════════════════════════════════════</info>');
        $output->writeln('<info>              LLM Model Evaluation Report</info>');
        $output->writeln('<info>═══════════════════════════════════════════════════════════════</info>');
        $output->writeln('');

        // Summary statistics
        $this->displaySummary($output, $report);

        // Models table
        $this->displayModelsTable($output, $report['models']);

        // Top performers
        $this->displayTopPerformers($output, $report['models']);

        // Areas for improvement
        $this->displayAreasForImprovement($output, $report['models']);

        $output->writeln('');
        $output->writeln("<comment>Report generated at: {$report['generated_at']}</comment>");
    }

    /**
     * Display summary statistics
     */
    private function displaySummary(OutputInterface $output, array $report): void
    {
        $stats = $report['statistics'];

        $output->writeln('<comment>Summary</comment>');
        $output->writeln(str_repeat('-', 60));

        $output->writeln("  Total Models Evaluated:  <info>{$stats['total_models']}</info>");

        if ($stats['evaluated_count'] > 0) {
            $output->writeln("  Models with Evaluations: <info>{$stats['evaluated_count']}</info>");
            $output->writeln("  Average Overall Score:   <info>" . number_format($stats['average_score'], 1) . "%</info>");
            $output->writeln("  Highest Score:           <info>" . number_format($stats['highest_score'], 1) . "%</info>");
            $output->writeln("  Lowest Score:            <info>" . number_format($stats['lowest_score'], 1) . "%</info>");

            $output->writeln('');
            $output->writeln('  Average Criterion Scores:');
            $output->writeln("    Logic:    <info>" . number_format($stats['avg_logic'], 1) . "%</info>");
            $output->writeln("    Syntax:   <info>" . number_format($stats['avg_syntax'], 1) . "%</info>");
            $output->writeln("    Output:   <info>" . number_format($stats['avg_output'], 1) . "%</info>");
        }

        $output->writeln('');
        $output->writeln('  Token Usage:');
        $output->writeln("    Total Tokens:     <info>{$stats['total_tokens']}</info>");
        $output->writeln("    Total Cost:       <info>\${$stats['total_cost']}</info>");
        $output->writeln("    Avg Cost/1K Tok:  <info>\${$stats['avg_cost_per_1k']}</info>");

        $output->writeln('');
    }

    /**
     * Display models table
     */
    private function displayModelsTable(OutputInterface $output, array $models): void
    {
        if (empty($models)) {
            $output->writeln('<comment>No models with evaluations found.</comment>');
            $output->writeln('');
            return;
        }

        $output->writeln('<comment>Model Rankings</comment>');
        $output->writeln(str_repeat('-', 60));

        $table = new Table($output);
        $table->setHeaders(['Rank', 'Model ID', 'Score', 'Logic', 'Syntax', 'Output', 'Tokens', 'Cost']);

        $rank = 1;
        foreach ($models as $model) {
            $scoreColor = $this->getScoreColor($model['overall_score']);

            $table->addRow([
                "#{$rank}",
                $this->truncateModelId($model['model_id']),
                "<{$scoreColor}>{$model['overall_score']}%</{$scoreColor}>",
                "{$model['logic_score']}%",
                "{$model['syntax_score']}%",
                "{$model['output_score']}%",
                number_format($model['total_tokens']),
                '$' . number_format($model['total_cost'], 4),
            ]);
            $rank++;
        }

        $table->render();
        $output->writeln('');
    }

    /**
     * Display top performers
     */
    private function displayTopPerformers(OutputInterface $output, array $models): void
    {
        $evaluatedModels = array_filter($models, fn($m) => $m['overall_score'] > 0);

        if (count($evaluatedModels) < 3) {
            return;
        }

        $output->writeln('<comment>🏆 Top Performers</comment>');
        $output->writeln(str_repeat('-', 60));

        $topModels = array_slice($evaluatedModels, 0, 3);

        foreach ($topModels as $index => $model) {
            $medals = ['🥇', '🥈', '🥉'];
            $output->writeln("  {$medals[$index]} <info>{$model['model_id']}</info>");
            $output->writeln("      Score: <info>{$model['overall_score']}%</info> | " .
                "Logic: {$model['logic_score']}% | " .
                "Syntax: {$model['syntax_score']}% | " .
                "Output: {$model['output_score']}%");
            $output->writeln('');
        }
    }

    /**
     * Display areas for improvement
     */
    private function displayAreasForImprovement(OutputInterface $output, array $models): void
    {
        $evaluatedModels = array_filter($models, fn($m) => $m['overall_score'] > 0);

        if (empty($evaluatedModels)) {
            return;
        }

        // Find models with lowest scores in each criterion
        $lowestLogic = null;
        $lowestSyntax = null;
        $lowestOutput = null;

        foreach ($evaluatedModels as $model) {
            if ($lowestLogic === null || $model['logic_score'] < $lowestLogic['score']) {
                $lowestLogic = ['model' => $model, 'score' => $model['logic_score']];
            }
            if ($lowestSyntax === null || $model['syntax_score'] < $lowestSyntax['score']) {
                $lowestSyntax = ['model' => $model, 'score' => $model['syntax_score']];
            }
            if ($lowestOutput === null || $model['output_score'] < $lowestOutput['score']) {
                $lowestOutput = ['model' => $model, 'score' => $model['output_score']];
            }
        }

        $output->writeln('<comment>📊 Areas for Improvement</comment>');
        $output->writeln(str_repeat('-', 60));

        if ($lowestLogic !== null) {
            $output->writeln("  <comment>Logic:</comment> {$lowestLogic['model']['model_id']} " .
                "(<info>{$lowestLogic['score']}%</info>)");
        }
        if ($lowestSyntax !== null) {
            $output->writeln("  <comment>Syntax:</comment> {$lowestSyntax['model']['model_id']} " .
                "(<info>{$lowestSyntax['score']}%</info>)");
        }
        if ($lowestOutput !== null) {
            $output->writeln("  <comment>Output:</comment> {$lowestOutput['model']['model_id']} " .
                "(<info>{$lowestOutput['score']}%</info>)");
        }

        $output->writeln('');
    }

    /**
     * Gather evaluation data for a single model
     */
    private function gatherModelEvaluationData(string $modelId): ?array
    {
        $modelPath = $this->storageManager->getModelPathFromId($modelId);

        if (!is_dir($modelPath)) {
            return null;
        }

        $totalTokens = 0;
        $totalCost = 0.0;
        $latestEval = null;
        $testCount = 0;

        // Scan for all test files
        $files = scandir($modelPath);
        foreach ($files as $file) {
            // Raw responses
            if (preg_match('/^(\d+)_raw_response\.json$/', $file, $matches)) {
                $testCount++;
                $content = json_decode(file_get_contents("{$modelPath}/{$file}"), true);
                $usage = $content['response']['usage'] ?? [];
                $totalTokens += (int) ($usage['total_tokens'] ?? 0);
                $totalCost += (float) ($usage['cost'] ?? 0);
            }

            // Evaluation files
            if (preg_match('/^(\d+)_evaluation\.json$/', $file, $matches)) {
                $content = json_decode(file_get_contents("{$modelPath}/{$file}"), true);
                $eval = $content['evaluation'] ?? [];

                // Keep the latest evaluation
                if ($latestEval === null || ($content['timestamp'] ?? '') > ($latestEval['timestamp'] ?? '')) {
                    $latestEval = [
                        'timestamp' => $content['timestamp'] ?? null,
                        'overall_score' => $eval['overall_score'] ?? 0,
                        'logic_score' => $eval['logic']['score'] ?? 0,
                        'syntax_score' => $eval['syntax']['score'] ?? 0,
                        'output_score' => $eval['output']['score'] ?? 0,
                    ];
                }
            }
        }

        return [
            'model_id' => $modelId,
            'test_count' => $testCount,
            'overall_score' => $latestEval['overall_score'] ?? 0,
            'logic_score' => $latestEval['logic_score'] ?? 0,
            'syntax_score' => $latestEval['syntax_score'] ?? 0,
            'output_score' => $latestEval['output_score'] ?? 0,
            'total_tokens' => $totalTokens,
            'total_cost' => $totalCost,
        ];
    }

    /**
     * Calculate overall statistics
     */
    private function calculateStatistics(array $modelIds): array
    {
        $stats = [
            'total_models' => count($modelIds),
            'evaluated_count' => 0,
            'average_score' => 0,
            'highest_score' => 0,
            'lowest_score' => 100,
            'avg_logic' => 0,
            'avg_syntax' => 0,
            'avg_output' => 0,
            'total_tokens' => 0,
            'total_cost' => 0.0,
            'avg_cost_per_1k' => 0,
        ];

        $totalTokens = 0;
        $totalCost = 0.0;
        $scores = [];
        $logicScores = [];
        $syntaxScores = [];
        $outputScores = [];

        foreach ($modelIds as $modelId) {
            $modelData = $this->gatherModelEvaluationData($modelId);
            if ($modelData !== null && $modelData['overall_score'] > 0) {
                $stats['evaluated_count']++;
                $scores[] = $modelData['overall_score'];
                $logicScores[] = $modelData['logic_score'];
                $syntaxScores[] = $modelData['syntax_score'];
                $outputScores[] = $modelData['output_score'];
                $totalTokens += $modelData['total_tokens'];
                $totalCost += $modelData['total_cost'];
            }
        }

        if (!empty($scores)) {
            $stats['average_score'] = array_sum($scores) / count($scores);
            $stats['highest_score'] = max($scores);
            $stats['lowest_score'] = min($scores);
            $stats['avg_logic'] = array_sum($logicScores) / count($logicScores);
            $stats['avg_syntax'] = array_sum($syntaxScores) / count($syntaxScores);
            $stats['avg_output'] = array_sum($outputScores) / count($outputScores);
        }

        $stats['total_tokens'] = $totalTokens;
        $stats['total_cost'] = $totalCost;

        if ($totalTokens > 0) {
            $stats['avg_cost_per_1k'] = ($totalCost / $totalTokens) * 1000;
        }

        return $stats;
    }

    /**
     * Get color based on score
     */
    private function getScoreColor(float $score): string
    {
        if ($score >= 80) {
            return 'green';
        } elseif ($score >= 60) {
            return 'yellow';
        } elseif ($score >= 40) {
            return 'red';
        } else {
            return 'magenta';
        }
    }

    /**
     * Truncate long model IDs for display
     */
    private function truncateModelId(string $modelId, int $maxLength = 30): string
    {
        if (strlen($modelId) <= $maxLength) {
            return $modelId;
        }

        return substr($modelId, 0, $maxLength - 3) . '...';
    }
}
