<?php

declare(strict_types=1);

use LLMScoring\Models\Model;
use LLMScoring\Report\CliReporter;
use LLMScoring\Storage\StorageManager;

describe('CliReporter', function () {
    describe('generateReport', function () {
        it('returns empty report when no models tested', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $storageManager = new StorageManager($testPath);
            $reporter = new CliReporter($storageManager);

            $report = $reporter->generateReport();

            expect($report['total_models'])->toBe(0);
            expect($report['models'])->toBeArray();
            expect(count($report['models']))->toBe(0);

            removeDirectoryCliReporter($testPath);
        });

        it('returns report with tested model data', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'provider');
            $storageManager = new StorageManager($testPath);

            // Save a raw response with usage data
            $storageManager->saveRawResponse($model, [
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 200,
                    'total_tokens' => 300,
                    'cost' => 0.001,
                ],
            ], 1);

            // Save an evaluation
            $storageManager->saveEvaluation($model, [
                'overall_score' => 85.5,
                'logic' => ['score' => 90, 'weight' => 40, 'weighted_score' => 36],
                'syntax' => ['score' => 80, 'weight' => 30, 'weighted_score' => 24],
                'output' => ['score' => 85, 'weight' => 30, 'weighted_score' => 25.5],
            ], 1);

            $reporter = new CliReporter($storageManager);
            $report = $reporter->generateReport();

            expect($report['total_models'])->toBe(1);
            expect(count($report['models']))->toBe(1);
            expect($report['models'][0]['model_id'])->toBe('test_model');
            expect($report['models'][0]['overall_score'])->toBe(85.5);
            expect($report['models'][0]['logic_score'])->toBe(90);
            expect($report['models'][0]['syntax_score'])->toBe(80);
            expect($report['models'][0]['output_score'])->toBe(85);
            expect($report['models'][0]['total_tokens'])->toBe(300);
            expect($report['models'][0]['total_cost'])->toBe(0.001);

            removeDirectoryCliReporter($testPath);
        });

        it('calculates correct statistics', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model1 = new Model('model/one', 'Model One', '0', '0', 4096, true, 1, 'provider');
            $model2 = new Model('model/two', 'Model Two', '0', '0', 4096, true, 1, 'provider');
            $storageManager = new StorageManager($testPath);

            // First model
            $storageManager->saveRawResponse($model1, ['usage' => ['total_tokens' => 100, 'cost' => 0.001]], 1);
            $storageManager->saveEvaluation($model1, [
                'overall_score' => 70,
                'logic' => ['score' => 80, 'weight' => 40],
                'syntax' => ['score' => 60, 'weight' => 30],
                'output' => ['score' => 70, 'weight' => 30],
            ], 1);

            // Second model
            $storageManager->saveRawResponse($model2, ['usage' => ['total_tokens' => 200, 'cost' => 0.002]], 1);
            $storageManager->saveEvaluation($model2, [
                'overall_score' => 90,
                'logic' => ['score' => 95, 'weight' => 40],
                'syntax' => ['score' => 85, 'weight' => 30],
                'output' => ['score' => 90, 'weight' => 30],
            ], 1);

            $reporter = new CliReporter($storageManager);
            $report = $reporter->generateReport();

            $stats = $report['statistics'];

            expect($stats['total_models'])->toBe(2);
            expect($stats['evaluated_count'])->toBe(2);
            expect($stats['average_score'])->toBe(80); // (70 + 90) / 2
            expect($stats['highest_score'])->toBe(90);
            expect($stats['lowest_score'])->toBe(70);
            expect($stats['total_tokens'])->toBe(300);
            expect($stats['total_cost'])->toBe(0.003);

            removeDirectoryCliReporter($testPath);
        });

        it('sorts models by score (highest first)', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model1 = new Model('model/low', 'Low Score', '0', '0', 4096, true, 1, 'provider');
            $model2 = new Model('model/high', 'High Score', '0', '0', 4096, true, 1, 'provider');
            $model3 = new Model('model/medium', 'Medium Score', '0', '0', 4096, true, 1, 'provider');
            $storageManager = new StorageManager($testPath);

            $storageManager->saveRawResponse($model1, ['usage' => ['total_tokens' => 100]], 1);
            $storageManager->saveEvaluation($model1, ['overall_score' => 50, 'logic' => ['score' => 50], 'syntax' => ['score' => 50], 'output' => ['score' => 50]], 1);

            $storageManager->saveRawResponse($model2, ['usage' => ['total_tokens' => 100]], 1);
            $storageManager->saveEvaluation($model2, ['overall_score' => 90, 'logic' => ['score' => 90], 'syntax' => ['score' => 90], 'output' => ['score' => 90]], 1);

            $storageManager->saveRawResponse($model3, ['usage' => ['total_tokens' => 100]], 1);
            $storageManager->saveEvaluation($model3, ['overall_score' => 70, 'logic' => ['score' => 70], 'syntax' => ['score' => 70], 'output' => ['score' => 70]], 1);

            $reporter = new CliReporter($storageManager);
            $report = $reporter->generateReport();

            expect($report['models'][0]['overall_score'])->toBe(90);
            expect($report['models'][1]['overall_score'])->toBe(70);
            expect($report['models'][2]['overall_score'])->toBe(50);

            removeDirectoryCliReporter($testPath);
        });

        it('excludes models without evaluations from statistics', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model1 = new Model('model/evaluated', 'Evaluated', '0', '0', 4096, true, 1, 'provider');
            $model2 = new Model('model/not-evaluated', 'Not Evaluated', '0', '0', 4096, true, 1, 'provider');
            $storageManager = new StorageManager($testPath);

            // Only evaluate one model
            $storageManager->saveRawResponse($model1, ['usage' => ['total_tokens' => 100]], 1);
            $storageManager->saveEvaluation($model1, ['overall_score' => 80, 'logic' => ['score' => 80], 'syntax' => ['score' => 80], 'output' => ['score' => 80]], 1);

            $storageManager->saveRawResponse($model2, ['usage' => ['total_tokens' => 100]], 1);
            // No evaluation saved for model2

            $reporter = new CliReporter($storageManager);
            $report = $reporter->generateReport();

            expect($report['total_models'])->toBe(2);
            expect($report['statistics']['evaluated_count'])->toBe(1);
            expect($report['statistics']['average_score'])->toBe(80);

            removeDirectoryCliReporter($testPath);
        });
    });
});

if (!function_exists('removeDirectoryCliReporter')) {
    function removeDirectoryCliReporter(string $path): void
    {
        if (is_dir($path)) {
            $items = scandir($path);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $fullPath = $path . '/' . $item;
                if (is_dir($fullPath)) {
                    removeDirectoryCliReporter($fullPath);
                } else {
                    unlink($fullPath);
                }
            }
            rmdir($path);
        }
    }
}
