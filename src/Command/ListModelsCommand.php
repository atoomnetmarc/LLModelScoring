<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use League\Csv\Reader;
use LLMScoring\Models\Model;
use LLMScoring\Models\ModelCollection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to list models from CSV
 */
class ListModelsCommand extends Command
{
    public function __construct()
    {
        parent::__construct('list-models');
        $this->setDescription('List models from CSV file');
    }

    protected function configure(): void
    {
        $this->addOption(
            'input',
            'i',
            InputOption::VALUE_OPTIONAL,
            'Input CSV file path (default: data/models.csv)',
            'data/models.csv'
        );
        $this->addOption(
            'enabled',
            'e',
            InputOption::VALUE_NONE,
            'Only show enabled models'
        );
        $this->addOption(
            'free-only',
            'f',
            InputOption::VALUE_NONE,
            'Only show free models'
        );
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_OPTIONAL,
            'Output format: table (default) or csv',
            'table'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputFile = $input->getOption('input');
        $showEnabled = $input->getOption('enabled');
        $freeOnly = $input->getOption('free-only');
        $format = $input->getOption('format');

        if (!file_exists($inputFile)) {
            $output->writeln("<error>CSV file not found: {$inputFile}</error>");
            $output->writeln('Run <comment>php llm-scoring.php fetch</comment> first.');
            return Command::FAILURE;
        }

        $collection = $this->loadFromCsv($inputFile);

        // Apply filters
        if ($showEnabled) {
            $collection = $collection->filterEnabled(true);
        }
        if ($freeOnly) {
            $collection = $collection->filterFree();
        }

        $output->writeln("Found {$collection->count()} models:");
        $output->writeln('');

        if ($format === 'csv') {
            $this->outputCsv($output, $collection);
        } else {
            $this->outputTable($output, $collection);
        }

        return Command::SUCCESS;
    }

    private function loadFromCsv(string $path): ModelCollection
    {
        $reader = Reader::from($path);
        $reader->setHeaderOffset(0);

        $models = [];
        foreach ($reader->getRecords() as $record) {
            $models[] = Model::fromCsvRow($record);
        }

        return new ModelCollection($models);
    }

    private function outputTable(OutputInterface $output, ModelCollection $collection): void
    {
        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Provider', 'Pricing In', 'Pricing Out', 'Context', 'Enabled']);

        foreach ($collection as $model) {
            $table->addRow([
                $model->getId(),
                $model->getName(),
                $model->getProvider() ?? '-',
                $model->getPricingInput() ?? '-',
                $model->getPricingOutput() ?? '-',
                $model->getContextLength() ?? '-',
                $model->isEnabled() ? '✓' : '✗',
            ]);
        }

        $table->render();
    }

    private function outputCsv(OutputInterface $output, ModelCollection $collection): void
    {
        $writer = \League\Csv\Writer::createFromString();
        $writer->insertOne([
            'model_id',
            'name',
            'provider',
            'pricing_input',
            'pricing_output',
            'context_length',
            'enabled',
        ]);

        foreach ($collection as $model) {
            $writer->insertOne([
                $model->getId(),
                $model->getName(),
                $model->getProvider() ?? '',
                $model->getPricingInput() ?? '',
                $model->getPricingOutput() ?? '',
                $model->getContextLength() ?? '',
                $model->isEnabled() ? '1' : '0',
            ]);
        }

        $output->writeln($writer->toString());
    }
}
