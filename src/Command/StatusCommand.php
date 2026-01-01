<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use LLMScoring\State\EvaluationState;
use LLMScoring\State\StateManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to show evaluation status
 */
class StatusCommand extends Command
{
    public function __construct()
    {
        parent::__construct('status');
        $this->setDescription('Show evaluation progress status');
    }

    protected function configure(): void
    {
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
        $experimentCode = $input->getOption('experiment-code') ?? 'default';
        $stateManager = new StateManager(null, $experimentCode);

        // Load models to get accurate total count
        $csvPath = 'data/models.csv';
        $totalModels = 0;
        if (file_exists($csvPath)) {
            $runner = new \LLMScoring\Runner\TestRunner(
                new \LLMScoring\Client\OpenRouterClient(),
                null,
                $experimentCode
            );
            $models = $runner->loadModelsFromCsv($csvPath, true, false);
            $totalModels = $models->count();
        }

        $progress = $stateManager->getProgressSummary($totalModels > 0 ? $totalModels : null);
        $states = $stateManager->getAllStates();

        $output->writeln('<info>LLModelScoring - Evaluation Status</info>');
        $output->writeln('');

        // Progress summary
        $output->writeln('<comment>Progress Summary</comment>');
        $output->writeln("  Total models:     {$progress['total']}");
        $output->writeln("  Completed:        {$progress['completed']}");
        $output->writeln("  Failed:           {$progress['failed']}");
        $output->writeln("  In progress:      {$progress['in_progress']}");
        $output->writeln("  Pending:          {$progress['pending']}");
        $output->writeln("  Progress:         {$progress['percent_complete']}%");
        $output->writeln('');

        // Model status breakdown
        $output->writeln('<comment>Model Status</comment>');

        foreach ($states as $state) {
            $statusIcon = match ($state->getStatus()) {
                EvaluationState::STATUS_COMPLETED => '<info>✓</info>',
                EvaluationState::STATUS_FAILED => '<error>✗</error>',
                EvaluationState::STATUS_IN_PROGRESS => '<comment>⋯</comment>',
                default => '<comment>○</comment>',
            };

            $statusText = match ($state->getStatus()) {
                EvaluationState::STATUS_COMPLETED => 'completed',
                EvaluationState::STATUS_FAILED => 'failed',
                EvaluationState::STATUS_IN_PROGRESS => 'in progress',
                default => 'pending',
            };

            $output->writeln("  {$statusIcon} {$state->getModelId()} - {$statusText}");

            if ($state->isFailed() && $state->getErrorMessage()) {
                $errorPreview = strlen($state->getErrorMessage()) > 60
                    ? substr($state->getErrorMessage(), 0, 60) . '...'
                    : $state->getErrorMessage();
                $output->writeln("    <error>Error: {$errorPreview}</error>");
            }
        }

        $output->writeln('');

        // Helpful commands
        $output->writeln('<comment>Commands</comment>');
        $output->writeln('  <info>php llm-scoring.php test --from-csv</info>       Test (resumes automatically)');
        $output->writeln('  <info>php llm-scoring.php test --from-csv --reset</info>   Reset and restart');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
