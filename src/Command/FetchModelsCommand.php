<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use League\Csv\Writer;
use LLMScoring\Client\OpenRouterClient;
use LLMScoring\Client\OpenRouterException;
use LLMScoring\Models\ModelCollection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to fetch models from OpenRouter and save to CSV
 */
class FetchModelsCommand extends Command
{
    public function __construct()
    {
        parent::__construct('fetch');
        $this->setDescription('Fetch all models from OpenRouter API and save to CSV');
    }

    protected function configure(): void
    {
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_OPTIONAL,
            'Output file path (default: data/models.csv)',
            'data/models.csv'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputFile = $input->getOption('output');

        $output->writeln('<info>Fetching models from OpenRouter API...</info>');

        $client = new OpenRouterClient();

        if (!$client->hasApiKey()) {
            $output->writeln('<error>No API key configured. Please set OPENROUTER_API_KEY in .env file.</error>');
            return Command::FAILURE;
        }

        try {
            $models = $client->fetchModels();
        } catch (OpenRouterException $e) {
            $output->writeln('<error>Failed to fetch models: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Ensure data directory exists
        $dataDir = dirname($outputFile);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        // Create CSV writer (using @ to suppress deprecation warning)
        $writer = @Writer::createFromPath($outputFile, 'w+');
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->setEscape('\\');

        // Write header
        $writer->insertOne([
            'model_id',
            'name',
            'pricing_input',
            'pricing_output',
            'context_length',
            'enabled',
            'priority',
            'provider',
        ]);

        // Write model data
        $output->writeln('Writing models to CSV...');
        $progressBar = new ProgressBar($output, $models->count());
        $progressBar->start();

        foreach ($models as $model) {
            $row = $model->toCsvRow();
            $writer->insertOne([
                $row['model_id'],
                $row['name'],
                $row['pricing_input'],
                $row['pricing_output'],
                $row['context_length'],
                $row['enabled'],
                $row['priority'],
                $row['provider'],
            ]);
            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln('');

        $output->writeln("<info>Successfully saved {$models->count()} models to {$outputFile}</info>");

        return Command::SUCCESS;
    }
}
