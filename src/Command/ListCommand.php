<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use LLMScoring\Storage\StorageManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to list all tested models
 */
class ListCommand extends Command
{
    public function __construct()
    {
        parent::__construct('list');
        $this->setDescription('List all tested models');
        $this->setHelp('This command lists all models that have been tested.');
    }

    protected function configure(): void
    {
        $this->addOption(
            'format',
            'f',
            InputOption::VALUE_OPTIONAL,
            'Output format: table (default) or json',
            'table'
        );
        $this->addOption(
            'details',
            'd',
            InputOption::VALUE_NONE,
            'Show additional details (full paths)'
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
        $format = $input->getOption('format');
        $details = $input->getOption('details');
        $experimentCode = $input->getOption('experiment-code') ?? 'default';

        $storageManager = new StorageManager(null, $experimentCode);
        $testedModels = $storageManager->getTestedModelIds();

        if (empty($testedModels)) {
            $output->writeln('<info>No models have been tested yet.</info>');
            $output->writeln('');
            $output->writeln('Run <comment>php llm-scoring.php test --from-csv</comment> to test models.');
            return Command::SUCCESS;
        }

        $modelData = $this->gatherModelData($storageManager, $testedModels);

        if ($format === 'json') {
            $output->writeln(json_encode($modelData, JSON_PRETTY_PRINT));
        } else {
            $this->outputTable($output, $modelData, $details);
        }

        $output->writeln('');
        $output->writeln("Total: " . count($testedModels) . " model(s)");

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{model_id: string, tests: int, last_tested: string, path: string, total_cost: float, total_tokens: int}>
     */
    private function gatherModelData(StorageManager $storageManager, array $modelIds): array
    {
        $data = [];

        foreach ($modelIds as $modelId) {
            $modelPath = $storageManager->getModelPathFromId($modelId);
            $tests = 0;
            $lastTested = null;
            $totalCost = 0.0;
            $totalTokens = 0;

            // Count tests, find last tested date, and aggregate costs
            if (is_dir($modelPath)) {
                $files = scandir($modelPath);
                foreach ($files as $file) {
                    if (preg_match('/^(\d+)_raw_response\.json$/', $file, $matches)) {
                        $tests++;
                        $content = json_decode(file_get_contents("{$modelPath}/{$file}"), true);
                        $timestamp = $content['timestamp'] ?? null;
                        if ($timestamp && ($lastTested === null || $timestamp > $lastTested)) {
                            $lastTested = $timestamp;
                        }

                        // Aggregate cost and tokens
                        $usage = $content['response']['usage'] ?? [];
                        $totalCost += (float) ($usage['cost'] ?? 0);
                        $totalTokens += (int) ($usage['total_tokens'] ?? 0);
                    }
                }
            }

            $data[] = [
                'model_id' => $modelId,
                'tests' => $tests,
                'last_tested' => $lastTested ? date('Y-m-d H:i', strtotime($lastTested)) : 'Unknown',
                'path' => $modelPath,
                'total_cost' => $totalCost,
                'total_tokens' => $totalTokens,
            ];
        }

        // Sort by last tested date (most recent first)
        usort($data, fn($a, $b) => strcmp($b['last_tested'], $a['last_tested']));

        return $data;
    }

    private function outputTable(OutputInterface $output, array $modelData, bool $verbose): void
    {
        $table = new Table($output);

        if ($verbose) {
            $table->setHeaders(['Model ID', 'Tests', 'Last Tested', 'Total Cost', 'Cost/1K', 'Path']);
        } else {
            $table->setHeaders(['Model ID', 'Tests', 'Last Tested', 'Total Cost', 'Cost/1K']);
        }

        foreach ($modelData as $model) {
            // Format cost columns
            $totalCostFormatted = '$' . number_format($model['total_cost'], 6);
            if ($model['total_tokens'] > 0) {
                $costPer1k = ($model['total_cost'] / $model['total_tokens']) * 1000;
                $costPer1kFormatted = '$' . number_format($costPer1k, 4);
            } else {
                $costPer1kFormatted = '$0.00';
            }

            $row = [
                $model['model_id'],
                $model['tests'],
                $model['last_tested'],
                $totalCostFormatted,
                $costPer1kFormatted,
            ];

            if ($verbose) {
                $row[] = $model['path'];
            }

            $table->addRow($row);
        }

        $table->render();

        // Show grand totals
        $grandCost = array_sum(array_column($modelData, 'total_cost'));
        $grandTokens = array_sum(array_column($modelData, 'total_tokens'));
        $output->writeln('');
        $output->writeln("Grand Total Cost: \$" . number_format($grandCost, 6));
        if ($grandTokens > 0) {
            $grandCostPer1k = ($grandCost / $grandTokens) * 1000;
            $output->writeln("Grand Total Cost/1K tokens: \$" . number_format($grandCostPer1k, 4));
        }
    }
}
