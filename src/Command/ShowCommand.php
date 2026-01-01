<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use LLMScoring\Storage\ModelPathNormalizer;
use LLMScoring\Storage\StorageManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to show model test data
 */
class ShowCommand extends Command
{
    public function __construct()
    {
        parent::__construct('show');
        $this->setDescription('Display model test data (prompts, responses, evaluations)');
        $this->setHelp('This command shows all stored data for a specific model.');
    }

    protected function configure(): void
    {
        $this->addArgument(
            'model_id',
            InputArgument::REQUIRED,
            'The model ID to show (e.g., meta-llama/llama-3.1-8b-instruct)'
        );
        $this->addOption(
            'test',
            't',
            InputOption::VALUE_OPTIONAL,
            'Specific test number to show (default: all tests)',
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modelId = $input->getArgument('model_id');
        $testNumber = $input->getOption('test');
        $experimentCode = $input->getOption('experiment-code') ?? 'default';
        $showRaw = $input->getOption('raw');

        $storageManager = new StorageManager(null, $experimentCode);
        $modelPath = ModelPathNormalizer::getModelPath($storageManager->getStoragePath(), $modelId);

        if (!is_dir($modelPath)) {
            $output->writeln("<error>Model not found: {$modelId}</error>");
            $output->writeln("Model data directory does not exist: {$modelPath}");
            $output->writeln('');
            $output->writeln('Use <comment>php llm-scoring.php list</comment> to see all tested models.');
            return Command::FAILURE;
        }

        $output->writeln("<info>Model: {$modelId}</info>");
        $output->writeln("Path: {$modelPath}");
        $output->writeln('');

        if ($testNumber !== null) {
            return $this->showSingleTest($output, $modelPath, (int) $testNumber, $showRaw);
        }

        return $this->showAllTests($output, $modelPath, $showRaw);
    }

    private function showSingleTest(OutputInterface $output, string $modelPath, int $testNumber, bool $showRaw): int
    {
        $promptFile = "{$modelPath}/" . sprintf('%02d_test_prompt.json', $testNumber);
        $responseFile = "{$modelPath}/" . sprintf('%02d_raw_response.json', $testNumber);
        $evalFile = "{$modelPath}/" . sprintf('%02d_evaluation.json', $testNumber);

        if (!file_exists($promptFile) && !file_exists($responseFile)) {
            $output->writeln("<error>Test {$testNumber} not found</error>");
            return Command::FAILURE;
        }

        if (file_exists($promptFile)) {
            $output->writeln("<comment>Test {$testNumber} - Prompt</comment>");
            $data = json_decode(file_get_contents($promptFile), true);
            if ($showRaw) {
                $output->writeln($this->formatJson($data));
            } else {
                $output->writeln($data['prompt'] ?? 'N/A');
            }
            $output->writeln('');
        }

        if (file_exists($responseFile)) {
            $output->writeln("<comment>Test {$testNumber} - Response</comment>");
            $data = json_decode(file_get_contents($responseFile), true);
            if ($showRaw) {
                $output->writeln($this->formatJson($data));
            } else {
                $this->formatAndDisplayResponse($output, $data);
            }
            $output->writeln('');
        }

        if (file_exists($evalFile)) {
            $output->writeln("<comment>Test {$testNumber} - Evaluation</comment>");
            $data = json_decode(file_get_contents($evalFile), true);
            if ($showRaw) {
                $output->writeln($this->formatJson($data));
            } else {
                $this->displayEvaluation($output, $data);
            }
        }

        return Command::SUCCESS;
    }

    private function showAllTests(OutputInterface $output, string $modelPath, bool $showRaw): int
    {
        $testNumbers = [];

        // Scan for all files matching the pattern XX_raw_response.json
        $files = scandir($modelPath);
        foreach ($files as $file) {
            if (preg_match('/^(\d+)_raw_response\.json$/', $file, $matches)) {
                $testNumbers[] = (int) $matches[1];
            }
        }

        // Sort test numbers numerically
        sort($testNumbers);

        if (empty($testNumbers)) {
            $output->writeln("<comment>No test data found for this model.</comment>");
            return Command::SUCCESS;
        }

        foreach ($testNumbers as $testNumber) {
            $output->writeln(str_repeat('-', 60));
            if ($this->showSingleTest($output, $modelPath, $testNumber, $showRaw) === Command::FAILURE) {
                continue;
            }
        }

        // Check for conversation
        $convFile = "{$modelPath}/conversation.json";
        if (file_exists($convFile)) {
            $output->writeln(str_repeat('-', 60));
            $output->writeln("<comment>Conversation History</comment>");
            $data = json_decode(file_get_contents($convFile), true);
            if ($showRaw) {
                $output->writeln($this->formatJson($data));
            } else {
                $this->displayConversation($output, $data);
            }
        }

        return Command::SUCCESS;
    }

    private function formatAndDisplayResponse(OutputInterface $output, array $data): void
    {
        $response = $data['response'] ?? [];
        $content = '';

        if (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
        } elseif (isset($response['choices'][0]['message']['reasoning_content'])) {
            $content = $response['choices'][0]['message']['reasoning_content'];
        } elseif (isset($response['content'])) {
            $content = $response['content'];
        }

        // Format code blocks nicely
        $content = preg_replace('/^```(\w*)\n/', "<info>```$1</info>\n", $content);
        $content = preg_replace('/\n```$/', "\n<info>```</info>", $content);

        $output->writeln($content);

        // Show token usage if available
        if (isset($response['usage'])) {
            $usage = $response['usage'];
            $output->writeln('');
            $output->writeln("<comment>Token Usage:</comment>");
            $output->writeln("  Prompt tokens: " . ($usage['prompt_tokens'] ?? 'N/A'));
            $output->writeln("  Completion tokens: " . ($usage['completion_tokens'] ?? 'N/A'));
            $output->writeln("  Total tokens: " . ($usage['total_tokens'] ?? 'N/A'));

            // Show cost if available
            $cost = $usage['cost'] ?? null;
            if ($cost !== null) {
                $costFormatted = '$' . number_format($cost, 8);
                $totalTokens = $usage['total_tokens'] ?? 0;
                if ($totalTokens > 0) {
                    $costPer1k = ($cost / $totalTokens) * 1000;
                    $costPer1kFormatted = '$' . number_format($costPer1k, 4);
                } else {
                    $costPer1kFormatted = 'N/A';
                }
                $output->writeln("  Cost: {$costFormatted}");
                $output->writeln("  Cost/1K tokens: {$costPer1kFormatted}");
            } else {
                $output->writeln("  Cost: \$0.00");
                $output->writeln("  Cost/1K tokens: \$0.00");
            }
        }
    }

    private function displayEvaluation(OutputInterface $output, array $data): void
    {
        $eval = $data['evaluation'] ?? [];

        if (empty($eval)) {
            $output->writeln('No evaluation data available.');
            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Criterion', 'Score', 'Weight', 'Weighted Score']);

        $totalWeighted = 0;
        foreach ($eval as $criterion => $details) {
            $score = $details['score'] ?? 0;
            $weight = $details['weight'] ?? 0;
            $weighted = $score * ($weight / 100);
            $totalWeighted += $weighted;

            $table->addRow([
                ucfirst($criterion),
                number_format($score, 2) . '%',
                $weight . '%',
                number_format($weighted, 2),
            ]);
        }

        $table->addRow(['', '', '<info>Total</info>', '<info>' . number_format($totalWeighted, 2) . '</info>']);
        $table->render();

        if (isset($details['feedback'])) {
            $output->writeln('');
            $output->writeln('<comment>Feedback:</comment>');
            $output->writeln($details['feedback']);
        }
    }

    private function displayConversation(OutputInterface $output, array $data): void
    {
        $messages = $data['messages'] ?? [];

        foreach ($messages as $message) {
            $role = ucfirst($message['role'] ?? 'Unknown');
            $content = $message['content'] ?? '';

            if ($role === 'User') {
                $output->writeln("<comment>{$role}:</comment> {$content}");
            } else {
                $output->writeln("<info>{$role}:</info> {$content}");
            }
            $output->writeln('');
        }
    }

    private function formatJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
