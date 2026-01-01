<?php

declare(strict_types=1);

namespace LLMScoring\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Welcome command showing help and quickstart
 */
class HomeCommand extends Command
{
    public function __construct()
    {
        parent::__construct('home');
        $this->setDescription('Show help and quickstart guide');
        $this->setHelp('This command displays a welcome message with quickstart instructions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<fg=cyan>╔═══════════════════════════════════════════════════════════════════╗</>');
        $output->writeln('<fg=cyan>║</>  <fg=white;options=bold>LLM Model Scoring CLI</>                                           <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</>  <fg=yellow>Version 1.0.0</>                                              <fg=cyan>║</>');
        $output->writeln('<fg=cyan>╚═══════════════════════════════════════════════════════════════════╝</>');
        $output->writeln('');

        $output->writeln('<fg=yellow>Quickstart:</>');
        $output->writeln('');
        $output->writeln('  <comment>1.</> Fetch available models from OpenRouter:');
        $output->writeln('     <info>php llm-scoring.php fetch</info>');
        $output->writeln('');
        $output->writeln('  <comment>2.</> Test a specific model:');
        $output->writeln('     <info>php llm-scoring.php test --model=meta-llama/llama-3.3-70b-instruct</info>');
        $output->writeln('');
        $output->writeln('  <comment>3.</> Run multiple tests from CSV:');
        $output->writeln('     <info>php llm-scoring.php test --from-csv=models.csv</info>');
        $output->writeln('');
        $output->writeln('  <comment>4.</> View test results:');
        $output->writeln('     <info>php llm-scoring.php list</info>');
        $output->writeln('');
        $output->writeln('  <comment>5.</> Evaluate model responses:');
        $output->writeln('     <info>php llm-scoring.php evaluate --model=meta-llama/llama-3.3-70b-instruct</info>');
        $output->writeln('');

        $output->writeln('<fg=yellow>Available Commands:</>');
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['Command', 'Description']);
        $table->setRows([
            ['<info>fetch</info>', 'Fetch available models from OpenRouter'],
            ['<info>list</info>', 'List all tested models'],
            ['<info>test</info>', 'Run tests on a model'],
            ['<info>evaluate</info>', 'Evaluate model responses'],
            ['<info>show</info>', 'Show model details'],
            ['<info>status</info>', 'Show system status'],
            ['<info>export</info>', 'Export models to CSV'],
        ]);
        $table->render();

        $output->writeln('');
        $output->writeln('<fg=yellow>Options:</>');
        $output->writeln('');
        $output->writeln('  <info>--help</info>, <info>-h</info>    Show help for a specific command');
        $output->writeln('  <info>--version</info>  Show application version');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
