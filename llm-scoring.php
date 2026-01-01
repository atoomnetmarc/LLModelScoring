#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * LLM Model Scoring CLI
 *
 * @package LLMScoring
 */

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use LLMScoring\Client\OpenRouterClient;

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

$application = new Symfony\Component\Console\Application('LLM Model Scoring', '1.0.0');

// Register commands
$application->add(new LLMScoring\Command\FetchModelsCommand());
$application->add(new LLMScoring\Command\ListModelsCommand());
$application->add(new LLMScoring\Command\ExportModelsCommand());
$application->add(new LLMScoring\Command\TestCommand());
$application->add(new LLMScoring\Command\StatusCommand());
$application->add(new LLMScoring\Command\ShowCommand());
$application->add(new LLMScoring\Command\ListCommand());
$application->add(new LLMScoring\Command\EvaluateCommand());
$application->add(new LLMScoring\Command\ReportCommand());
$application->add(new LLMScoring\Command\StatsCommand());
$application->add(new LLMScoring\Command\HomeCommand());

$application->setDefaultCommand('home');

$application->get('home')->setHelp('This command displays a welcome message with quickstart instructions.');

$application->run();
