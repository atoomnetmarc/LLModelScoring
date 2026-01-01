<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use League\Csv\Reader;
use League\Csv\Writer;
use LLMScoring\Models\Model;
use LLMScoring\Models\ModelCollection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to export models from CSV to various formats
 */
class ExportModelsCommand extends Command
{
    public function __construct()
    {
        parent::__construct('export-models');
        $this->setDescription('Export models from CSV to various formats');
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
            'output',
            'o',
            InputOption::VALUE_OPTIONAL,
            'Output file path (default: stdout)',
            null
        );
        $this->addOption(
            'format',
            'f',
            InputOption::VALUE_OPTIONAL,
            'Output format: csv (default) or json',
            'csv'
        );
        $this->addOption(
            'enabled',
            null,
            InputOption::VALUE_NONE,
            'Only export enabled models'
        );
        $this->addOption(
            'free-only',
            null,
            InputOption::VALUE_NONE,
            'Only export free models'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputFile = $input->getOption('input');
        $outputFile = $input->getOption('output');
        $format = $input->getOption('format');
        $enabledOnly = $input->getOption('enabled');
        $freeOnly = $input->getOption('free-only');

        if (!file_exists($inputFile)) {
            $output->writeln("<error>CSV file not found: {$inputFile}</error>");
            $output->writeln('Run <comment>php llm-scoring.php fetch-models</comment> first.');
            return Command::FAILURE;
        }

        $collection = $this->loadFromCsv($inputFile);

        // Apply filters
        if ($enabledOnly) {
            $collection = $collection->filterEnabled(true);
        }
        if ($freeOnly) {
            $collection = $collection->filterFree();
        }

        $output->writeln("Exporting {$collection->count()} models...");

        if ($format === 'json') {
            return $this->exportJson($output, $collection, $outputFile);
        }

        return $this->exportCsv($output, $collection, $outputFile);
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

    private function exportCsv(OutputInterface $output, ModelCollection $collection, ?string $outputFile): int
    {
        if ($outputFile) {
            $writer = @Writer::createFromPath($outputFile, 'w+');
        } else {
            $writer = Writer::createFromString();
        }

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

        foreach ($collection as $model) {
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
        }

        if ($outputFile) {
            $output->writeln("<info>Exported to {$outputFile}</info>");
        } else {
            $output->writeln($writer->toString());
        }

        return Command::SUCCESS;
    }

    private function exportJson(OutputInterface $output, ModelCollection $collection, ?string $outputFile): int
    {
        $data = [];
        foreach ($collection as $model) {
            $data[] = $model->toArray();
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($outputFile) {
            file_put_contents($outputFile, $json);
            $output->writeln("<info>Exported to {$outputFile}</info>");
        } else {
            $output->writeln($json);
        }

        return Command::SUCCESS;
    }
}
