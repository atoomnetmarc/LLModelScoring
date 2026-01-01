<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use LLMScoring\Client\OpenRouterClient;
use LLMScoring\Evaluator\ContentEvaluator;
use LLMScoring\Storage\ModelPathNormalizer;
use LLMScoring\Storage\StorageManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to evaluate stored model content
 */
class EvaluateCommand extends Command
{
    public function __construct()
    {
        parent::__construct('evaluate');
        $this->setDescription('Evaluate stored model content');
        $this->setHelp('This command evaluates already stored model responses using an LLM.');
    }

    protected function configure(): void
    {
        $this->addArgument(
            'model_id',
            InputArgument::OPTIONAL,
            'The model ID to evaluate (e.g., meta-llama/llama-3.1-8b-instruct). If omitted, evaluates all unevaluated models.'
        );
        $this->addOption(
            'test',
            't',
            InputOption::VALUE_OPTIONAL,
            'Test number to evaluate (default: latest)',
            null
        );
        $this->addOption(
            'model',
            'm',
            InputOption::VALUE_OPTIONAL,
            'The evaluator model to use (default: from EVALUATOR_MODEL env)',
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
            'raw',
            'r',
            InputOption::VALUE_NONE,
            'Show raw JSON output'
        );
        $this->addOption(
            'all',
            'a',
            InputOption::VALUE_NONE,
            'Evaluate all unevaluated models (same as omitting model_id)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $modelId = $input->getArgument('model_id');
        $evaluateAll = $input->getOption('all') || $modelId === null;
        $requestedTestNumber = $input->getOption('test');
        $evaluatorModelId = $input->getOption('model');
        $experimentCode = $input->getOption('experiment-code') ?? 'default';
        $showRaw = $input->getOption('raw');

        // Check for API key
        $client = new OpenRouterClient();
        if (!$client->hasApiKey()) {
            $io->error('OpenRouter API key not configured. Please set OPENROUTER_API_KEY in .env');
            return Command::FAILURE;
        }

        $storageManager = new StorageManager(null, $experimentCode);

        // If evaluating all unevaluated models
        if ($evaluateAll) {
            return $this->evaluateAllUnevaluated($io, $client, $storageManager, $requestedTestNumber, $evaluatorModelId, $showRaw);
        }

        // Single model evaluation
        $modelPath = ModelPathNormalizer::getModelPath($storageManager->getStoragePath(), $modelId);

        if (!is_dir($modelPath)) {
            $io->error("Model not found: {$modelId}");
            $io->writeln("Use <comment>php llm-scoring.php list</comment> to see all tested models.");
            return Command::FAILURE;
        }

        // Find available test numbers
        $testNumbers = [];
        $files = scandir($modelPath);
        foreach ($files as $file) {
            if (preg_match('/^(\d+)_raw_response\.json$/', $file, $matches)) {
                $testNumbers[] = (int) $matches[1];
            }
        }

        if (empty($testNumbers)) {
            $io->error("No test data found for model {$modelId}");
            return Command::FAILURE;
        }

        // Sort test numbers and get the latest if not specified
        sort($testNumbers);
        $testNumber = $requestedTestNumber !== null
            ? (int) $requestedTestNumber
            : end($testNumbers);

        if (!in_array($testNumber, $testNumbers)) {
            $io->error("Test {$testNumber} not found for model {$modelId}");
            $io->writeln("Available tests: " . implode(', ', $testNumbers));
            return Command::FAILURE;
        }

        // Load prompt
        $promptFile = "{$modelPath}/" . sprintf('%02d_test_prompt.json', $testNumber);
        if (!file_exists($promptFile)) {
            $io->error("Prompt not found for test {$testNumber}");
            return Command::FAILURE;
        }

        $promptData = json_decode(file_get_contents($promptFile), true);
        $prompt = $promptData['prompt'] ?? '';

        // Load raw response
        $responseFile = "{$modelPath}/" . sprintf('%02d_raw_response.json', $testNumber);
        if (!file_exists($responseFile)) {
            $io->error("Response not found for test {$testNumber}");
            return Command::FAILURE;
        }

        $responseData = json_decode(file_get_contents($responseFile), true);
        $response = $responseData['response'] ?? [];

        // Extract content from response
        $content = '';
        if (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
        } elseif (isset($response['choices'][0]['message']['reasoning_content'])) {
            $content = $response['choices'][0]['message']['reasoning_content'];
        }

        $io->writeln("<info>Evaluating model:</info> {$modelId}");
        $io->writeln("<info>Test number:</info> {$testNumber}");
        $io->writeln('');

        // Show the content being evaluated
        $io->section('Prompt:');
        $io->writeln("<comment>{$prompt}</comment>");
        $io->writeln('');

        $io->section('Content to evaluate:');
        $io->writeln("<comment>{$content}</comment>");
        $io->writeln('');

        // Create evaluator
        $evaluator = new ContentEvaluator($client, $evaluatorModelId);

        try {
            $result = $evaluator->evaluate($content, $prompt, $modelId, $modelId, null);

            if ($showRaw) {
                $io->writeln('<comment>Raw JSON:</comment>');
                $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }

            // Display evaluation results
            $this->displayEvaluationResults($io, $result);

            // Save evaluation
            $savedPath = $storageManager->saveEvaluation(
                new \LLMScoring\Models\Model($modelId, $modelId),
                $result['evaluation'],
                $testNumber
            );

            $io->writeln('');
            $io->writeln("<info>Evaluation saved to:</info> {$savedPath}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Evaluation failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function displayEvaluationResults(SymfonyStyle $io, array $result): void
    {
        $eval = $result['evaluation'] ?? [];

        if (empty($eval)) {
            $io->warning('No evaluation data available.');
            return;
        }

        // Overall score
        $overallScore = $eval['overall_score'] ?? 0;
        $scoreColor = $overallScore >= 70 ? 'green' : ($overallScore >= 50 ? 'yellow' : 'red');
        $io->writeln("<{$scoreColor}>Overall Score: {$overallScore}%</{$scoreColor}>");
        $io->writeln('');

        // Detailed scores table
        $io->section('Detailed Scores');
        $table = new Table($io);
        $table->setHeaders(['Criterion', 'Score', 'Weight', 'Weighted Score', 'Feedback']);

        $criteria = ['logic', 'syntax', 'output'];

        foreach ($criteria as $criterion) {
            $details = $eval[$criterion] ?? [];
            $score = $details['score'] ?? 0;
            $weight = $details['weight'] ?? 0;
            $weighted = $details['weighted_score'] ?? 0;
            $feedback = $details['feedback'] ?? 'No feedback';

            $table->addRow([
                ucfirst($criterion),
                "{$score}%",
                "{$weight}%",
                $weighted,
                substr($feedback, 0, 50) . (strlen($feedback) > 50 ? '...' : ''),
            ]);
        }

        $table->render();
        $io->writeln('');

        // Strengths
        $strengths = $eval['strengths'] ?? [];
        if (!empty($strengths)) {
            $io->section('Strengths');
            foreach ($strengths as $strength) {
                $io->writeln("  ✓ {$strength}");
            }
            $io->writeln('');
        }

        // Weaknesses
        $weaknesses = $eval['weaknesses'] ?? [];
        if (!empty($weaknesses)) {
            $io->section('Weaknesses');
            foreach ($weaknesses as $weakness) {
                $io->writeln("  ✗ {$weakness}");
            }
            $io->writeln('');
        }

        // Suggestions
        $suggestions = $eval['suggestions'] ?? [];
        if (!empty($suggestions)) {
            $io->section('Suggestions');
            foreach ($suggestions as $suggestion) {
                $io->writeln("  → {$suggestion}");
            }
        }

        // Evaluator info
        $io->writeln('');
        $io->writeln("<info>Evaluator Model:</info> {$result['evaluator_model']}");
        $io->writeln("<info>Evaluated At:</info> {$result['timestamp']}");
    }

    private function evaluateAllUnevaluated(
        SymfonyStyle $io,
        OpenRouterClient $client,
        StorageManager $storageManager,
        ?int $requestedTestNumber,
        ?string $evaluatorModelId,
        bool $showRaw
    ): int {
        $unevaluatedModelIds = $storageManager->getUnevaluatedModelIds();

        if (empty($unevaluatedModelIds)) {
            $io->success('All models have been evaluated!');
            return Command::SUCCESS;
        }

        $io->writeln("<info>Found " . count($unevaluatedModelIds) . " unevaluated model(s)</info>");
        $io->newLine();

        $successCount = 0;
        $failCount = 0;

        foreach ($unevaluatedModelIds as $modelId) {
            $io->writeln("<comment>Evaluating: {$modelId}</comment>");
            $io->newLine();

            $modelPath = ModelPathNormalizer::getModelPath($storageManager->getStoragePath(), $modelId);

            // Find available test numbers
            $testNumbers = [];
            $files = scandir($modelPath);
            foreach ($files as $file) {
                if (preg_match('/^(\d+)_raw_response\.json$/', $file, $matches)) {
                    $testNumbers[] = (int) $matches[1];
                }
            }

            if (empty($testNumbers)) {
                $io->warning("No test data found for model {$modelId}");
                $failCount++;
                continue;
            }

            sort($testNumbers);
            $testNumber = $requestedTestNumber ?? end($testNumbers);

            // Load prompt
            $promptFile = "{$modelPath}/" . sprintf('%02d_test_prompt.json', $testNumber);
            if (!file_exists($promptFile)) {
                $io->warning("Prompt not found for test {$testNumber}");
                $failCount++;
                continue;
            }

            $promptData = json_decode(file_get_contents($promptFile), true);
            $prompt = $promptData['prompt'] ?? '';

            // Load raw response
            $responseFile = "{$modelPath}/" . sprintf('%02d_raw_response.json', $testNumber);
            if (!file_exists($responseFile)) {
                $io->warning("Response not found for test {$testNumber}");
                $failCount++;
                continue;
            }

            $responseData = json_decode(file_get_contents($responseFile), true);
            $response = $responseData['response'] ?? [];

            // Extract content from response
            $content = '';
            if (isset($response['choices'][0]['message']['content'])) {
                $content = $response['choices'][0]['message']['content'];
            } elseif (isset($response['choices'][0]['message']['reasoning_content'])) {
                $content = $response['choices'][0]['message']['reasoning_content'];
            }

            $evaluator = new ContentEvaluator($client, $evaluatorModelId);

            try {
                $result = $evaluator->evaluate($content, $prompt, $modelId, $modelId, null);

                if ($showRaw) {
                    $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->displayEvaluationResults($io, $result);
                }

                // Save evaluation
                $savedPath = $storageManager->saveEvaluation(
                    new \LLMScoring\Models\Model($modelId, $modelId),
                    $result['evaluation'],
                    $testNumber
                );

                $io->writeln("<info>✓ Saved:</info> {$savedPath}");
                $successCount++;
            } catch (\Exception $e) {
                $io->error("✗ Failed: {$e->getMessage()}");
                $failCount++;
            }

            $io->newLine();
            $io->writeln('---');
            $io->newLine();
        }

        $io->writeln("<info>Evaluation complete!</info>");
        $io->writeln("  <fg=green>✓ Success:</fg=green> {$successCount}");
        $io->writeln("  <fg=red>✗ Failed:</fg=red> {$failCount}");

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
