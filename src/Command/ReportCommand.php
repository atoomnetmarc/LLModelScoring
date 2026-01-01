<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use LLMScoring\Report\CliReporter;
use LLMScoring\Report\HtmlReporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to generate evaluation reports
 */
class ReportCommand extends Command
{
    public function __construct()
    {
        parent::__construct('report');
        $this->setDescription('Generate evaluation reports');
        $this->setHelp('This command generates reports from model evaluations.');
    }

    protected function configure(): void
    {
        $this->addOption(
            'format',
            'f',
            InputOption::VALUE_OPTIONAL,
            'Output format: cli (default) or html',
            'cli'
        );
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_OPTIONAL,
            'Output file path for HTML format (default: {questionCode}/results.html)',
            null
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
        $userOutputPath = $input->getOption('output');
        $experimentCode = $input->getOption('experiment-code') ?? 'default';

        // Default output path is data/{experimentCode}/results.html
        $outputPath = $userOutputPath ?? "data/{$experimentCode}/results.html";

        if ($format === 'html') {
            return $this->generateHtmlReport($output, $outputPath, $experimentCode);
        }

        return $this->generateCliReport($output, $experimentCode);
    }

    /**
     * Generate CLI report
     */
    private function generateCliReport(OutputInterface $output, string $questionCode): int
    {
        $reporter = new CliReporter(null, $questionCode);
        $reportData = $reporter->generateReport();

        if ($reportData['total_models'] === 0) {
            $output->writeln('<info>No models have been tested yet.</info>');
            $output->writeln('');
            $output->writeln('Run <comment>php llm-scoring.php test --from-csv</comment> to test models.');
            return Command::SUCCESS;
        }

        $reporter->displayReport($output, $reportData);

        return Command::SUCCESS;
    }

    /**
     * Generate HTML report
     */
    private function generateHtmlReport(OutputInterface $output, string $outputPath, string $questionCode): int
    {
        $reporter = new HtmlReporter(null, $questionCode);

        try {
            $reportData = $reporter->generateReport($outputPath);

            if ($reportData['total_models'] === 0) {
                $output->writeln('<info>No models have been tested yet.</info>');
                $output->writeln('');
                $output->writeln('Run <comment>php llm-scoring.php test --from-csv</comment> to test models.');
                return Command::SUCCESS;
            }

            $output->writeln('<info>═══════════════════════════════════════════════════════════════</info>');
            $output->writeln('<info>              HTML Report Generated</info>');
            $output->writeln('<info>═══════════════════════════════════════════════════════════════</info>');
            $output->writeln('');
            $output->writeln("<info>Report saved to:</info> {$outputPath}");
            $output->writeln('');

            // Show summary
            $stats = $reportData['statistics'];
            $output->writeln('<comment>Summary:</comment>');
            $output->writeln("  Total Models:     {$stats['total_models']}");
            $output->writeln("  Evaluated:        {$stats['evaluated_count']}");
            $output->writeln("  Average Score:    {$stats['average_score']}%");
            $output->writeln("  Highest Score:    {$stats['highest_score']}%");
            $output->writeln('');
            $output->writeln("<comment>Open the HTML file in a browser to view the full report.</comment>");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Failed to generate report: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
