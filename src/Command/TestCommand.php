<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use LLMScoring\Client\OpenRouterClient;
use LLMScoring\Client\OpenRouterException;
use LLMScoring\Runner\TestRunner;
use LLMScoring\State\StateManager;
use LLMScoring\Storage\StorageManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to test models from CSV
 */
class TestCommand extends Command
{
    public function __construct()
    {
        parent::__construct('test');
        $this->setDescription('Test models from CSV file');
    }

    protected function configure(): void
    {
        $this->addOption(
            'from-csv',
            null,
            InputOption::VALUE_OPTIONAL,
            'Input CSV file path (default: data/{experimentCode}/models.csv or data/models.csv)',
            null
        );
        $this->addOption(
            'all',
            'a',
            InputOption::VALUE_NONE,
            'Test all models, even disabled'
        );
        $this->addOption(
            'free-only',
            'f',
            InputOption::VALUE_NONE,
            'Only test free models'
        );
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_OPTIONAL,
            'Limit number of models to test',
            0
        );
        $this->addOption(
            'prompt',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Prompt to send to models (required, read from data/task.md if not provided)',
            null
        );
        $this->addOption(
            'experiment-code',
            'e',
            InputOption::VALUE_OPTIONAL,
            'Experiment code for organizing results (default: default)',
            'default'
        );
        $this->addOption(
            'reset',
            null,
            InputOption::VALUE_NONE,
            'Reset evaluation state before starting (start fresh, ignore previous results)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = $input->getOption('all');
        // Default is to test only enabled models (enabledOnly = true means filter to enabled only)
        // With new --all flag: --all means include disabled, no flag means only enabled
        $enabledOnly = !$all;
        $freeOnly = $input->getOption('free-only');
        $limit = (int) $input->getOption('limit');
        $prompt = $input->getOption('prompt');
        $experimentCode = $input->getOption('experiment-code') ?? 'default';
        $reset = $input->getOption('reset');

        // Determine CSV path: use explicit --from-csv or fall back to experiment directory
        $userCsvPath = $input->getOption('from-csv');
        $experimentCsvPath = "data/{$experimentCode}/models.csv";
        $csvPath = $userCsvPath ?? (file_exists($experimentCsvPath) ? $experimentCsvPath : 'data/models.csv');

        // Determine task file path: use experiment directory or default data/task.md
        $experimentTaskPath = "data/{$experimentCode}/task.md";
        $taskFilePath = file_exists($experimentTaskPath) ? $experimentTaskPath : 'data/task.md';

        // Load prompt from task.md if not provided via --prompt
        if (empty($prompt)) {
            if (!file_exists($taskFilePath)) {
                $suggestedPath = "data/{$experimentCode}/task.md";
                $output->writeln("<error>Task file not found.</error>");
                $output->writeln("<comment>Create {$suggestedPath} with:</comment>");
                $output->writeln("```markdown");
                $output->writeln("# Task Definition");
                $output->writeln("## Task Prompt");
                $output->writeln("```markdown");
                $output->writeln("Your prompt here");
                $output->writeln("```");
                $output->writeln("## Content Type");
                $output->writeln("`type`");
                $output->writeln("```");
                $output->writeln('');
                $output->writeln("<comment>Optional: Create data/{$experimentCode}/evaluator-hints.md for evaluation guidance.</comment>");
                return Command::FAILURE;
            }
            $taskContent = file_get_contents($taskFilePath);
            // Check if file is empty
            if (filesize($taskFilePath) === 0) {
                $output->writeln("<error>Task file is empty.</error>");
                $output->writeln("<comment>Edit data/{$experimentCode}/task.md to define your task prompt.</comment>");
                return Command::FAILURE;
            }
            // Extract prompt from task.md (between ```markdown and ```)
            if (preg_match('/```markdown\s*\n(.*?)\n\s*```/s', $taskContent, $matches)) {
                $prompt = trim($matches[1]);
            } else {
                // Use entire content if no code block found
                $prompt = trim($taskContent);
            }
        }

        if (empty($prompt)) {
            $output->writeln('<error>No prompt provided in data/task.md or via --prompt option.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>LLModelScoring - Model Testing</info>');
        $output->writeln('');

        // Check if CSV file exists
        if (!file_exists($csvPath)) {
            $output->writeln("<error>CSV file not found.</error>");
            if ($userCsvPath !== null) {
                $output->writeln("<comment>Run <comment>php llm-scoring.php fetch-models</comment> first or create {$userCsvPath}.</comment>");
            } else {
                $output->writeln("<comment>Create data/{$experimentCode}/models.csv with model definitions.</comment>");
            }
            return Command::FAILURE;
        }

        // Initialize client and runner
        $client = new OpenRouterClient();

        if (!$client->hasApiKey()) {
            $output->writeln('<error>No API key configured. Please set OPENROUTER_API_KEY in .env file.</error>');
            return Command::FAILURE;
        }

        // Initialize state manager with experiment code
        $stateManager = new StateManager(null, $experimentCode);

        // Reset state if requested
        if ($reset) {
            $output->writeln('<comment>Resetting evaluation state...</comment>');
            $stateManager->resetAll();
            $output->writeln('');
        }

        $storageManager = new StorageManager(null, $experimentCode);
        $runner = new TestRunner($client, $storageManager, $experimentCode);

        // Load models from CSV
        try {
            $models = $runner->loadModelsFromCsv($csvPath, $enabledOnly, $freeOnly);
        } catch (\RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        if ($models->count() === 0) {
            $output->writeln('<info>No models found matching criteria.</info>');
            return Command::SUCCESS;
        }

        // Get list of already completed models (resume is now default)
        $completedModelIds = $stateManager->getCompletedModelIds();
        if (count($completedModelIds) > 0) {
            $output->writeln("<info>Resuming - " . count($completedModelIds) . " models already completed</info>", OutputInterface::VERBOSITY_VERBOSE);
        }

        $modelsArray = $models->toArray();

        // Filter out completed models (resume is default)
        if (count($completedModelIds) > 0) {
            $modelsArray = array_filter($modelsArray, function ($model) use ($completedModelIds) {
                return !in_array($model->getId(), $completedModelIds);
            });
            $modelsArray = array_values($modelsArray);
        }

        if (count($modelsArray) === 0) {
            $output->writeln('<info>All models have already been tested. Use --reset to start over.</info>');
            return Command::SUCCESS;
        }

        $output->writeln("Found " . count($modelsArray) . " models to test");
        $output->writeln("Prompt: " . substr($prompt, 0, 60) . (strlen($prompt) > 60 ? '...' : ''));
        $output->writeln('');

        // Apply limit if specified
        if ($limit > 0) {
            $modelsArray = array_slice($modelsArray, 0, $limit);
            $output->writeln("Limiting to {$limit} models");
        }

        // Run tests
        $progressBar = new ProgressBar($output, count($modelsArray));
        $progressBar->start();

        $results = [];
        $testNumber = 1;
        foreach ($modelsArray as $model) {
            // Output current model being tested
            $output->writeln("  Testing: " . $model->getName() . " (" . $model->getId() . ")");

            // Mark as in progress
            $stateManager->startModel($model);

            try {
                // Test model and save results to storage
                $result = $runner->testModelAndSave($model, $prompt, $testNumber);
                $result['status'] = 'success';
                $testNumber++;

                // Extract content length from response
                $responseContent = $result['response']['choices'][0]['message']['content'] ?? '';
                $responseLength = is_string($responseContent) ? strlen($responseContent) : 0;

                // Mark as completed
                $stateManager->completeModel($model, [
                    'prompt' => $prompt,
                    'response_length' => $responseLength,
                ]);

                $results[] = $result;
            } catch (OpenRouterException $e) {
                // Mark as failed
                $stateManager->failModel($model, $e->getMessage());

                $results[] = [
                    'model_id' => $model->getId(),
                    'model_name' => $model->getName(),
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln('');
        $output->writeln('');

        // Summary
        $successCount = count(array_filter($results, fn($r) => $r['status'] === 'success'));
        $failedCount = count($results) - $successCount;

        // Get progress summary (pass total models count for accurate progress)
        $progressSummary = $stateManager->getProgressSummary($models->count());

        $output->writeln("<info>Testing complete!</info>");
        $output->writeln("  This run: {$successCount} successful, {$failedCount} failed");
        $output->writeln("  Total progress: {$progressSummary['completed']}/{$progressSummary['total']} ({$progressSummary['percent_complete']}%)");

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
