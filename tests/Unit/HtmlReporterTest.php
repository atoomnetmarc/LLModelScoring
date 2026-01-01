<?php

declare(strict_types=1);

use LLMScoring\Models\Model;
use LLMScoring\Report\HtmlReporter;
use LLMScoring\Storage\StorageManager;

describe('HtmlReporter', function () {
    describe('getReportData', function () {
        it('returns empty report when no models tested', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $storageManager = new StorageManager($testPath);
            $reporter = new HtmlReporter($storageManager);

            $report = $reporter->getReportData();

            expect($report['total_models'])->toBe(0);
            expect($report['models'])->toBeArray();
            expect(count($report['models']))->toBe(0);

            removeDirectoryHtml($testPath);
        });

        it('returns report with tested model data', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'provider');
            $storageManager = new StorageManager($testPath);

            $storageManager->saveRawResponse($model, [
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 200,
                    'total_tokens' => 300,
                    'cost' => 0.001,
                ],
            ], 1);

            $storageManager->saveEvaluation($model, [
                'overall_score' => 85.5,
                'logic' => ['score' => 90, 'weight' => 40],
                'syntax' => ['score' => 80, 'weight' => 30],
                'output' => ['score' => 85, 'weight' => 30],
            ], 1);

            $reporter = new HtmlReporter($storageManager);
            $report = $reporter->getReportData();

            expect($report['total_models'])->toBe(1);
            expect(count($report['models']))->toBe(1);
            expect($report['models'][0]['model_id'])->toBe('test_model');
            expect($report['models'][0]['overall_score'])->toBe(85.5);

            removeDirectoryHtml($testPath);
        });
    });

    describe('generateReport', function () {
        it('generates HTML file with correct structure', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $outputPath = $testPath . '/report.html';
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'provider');
            $storageManager = new StorageManager($testPath);

            $storageManager->saveRawResponse($model, [
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 200,
                    'total_tokens' => 300,
                    'cost' => 0.001,
                ],
            ], 1);

            $storageManager->saveEvaluation($model, [
                'overall_score' => 85.5,
                'logic' => ['score' => 90, 'weight' => 40],
                'syntax' => ['score' => 80, 'weight' => 30],
                'output' => ['score' => 85, 'weight' => 30],
            ], 1);

            $reporter = new HtmlReporter($storageManager);
            $report = $reporter->generateReport($outputPath);

            expect(file_exists($outputPath))->toBeTrue();

            $html = file_get_contents($outputPath);
            expect(strpos($html, '<!DOCTYPE html>'))->not->toBeFalse();
            expect(strpos($html, '<title>LLM Model Evaluation Report</title>'))->not->toBeFalse();
            expect(strpos($html, 'Total Models'))->not->toBeFalse();
            expect(strpos($html, 'test_model'))->not->toBeFalse();

            removeDirectoryHtml($testPath);
        });

        it('creates directory if it does not exist', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $nestedPath = $testPath . '/nested/directory';
            $outputPath = $nestedPath . '/report.html';

            expect(is_dir($nestedPath))->toBeFalse();

            $storageManager = new StorageManager($testPath);
            $reporter = new HtmlReporter($storageManager);

            // No models, but it should still create the directory
            $reporter->generateReport($outputPath);

            expect(is_dir($nestedPath))->toBeTrue();

            removeDirectoryHtml($testPath);
        });

        it('includes top performers section when there are enough models', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $outputPath = $testPath . '/report.html';

            $model1 = new Model('model/good', 'Good Model', '0', '0', 4096, true, 1, 'provider');
            $model2 = new Model('model/better', 'Better Model', '0', '0', 4096, true, 1, 'provider');
            $model3 = new Model('model/best', 'Best Model', '0', '0', 4096, true, 1, 'provider');

            $storageManager = new StorageManager($testPath);

            $storageManager->saveRawResponse($model1, ['usage' => ['total_tokens' => 100]], 1);
            $storageManager->saveEvaluation($model1, ['overall_score' => 70, 'logic' => ['score' => 70], 'syntax' => ['score' => 70], 'output' => ['score' => 70]], 1);

            $storageManager->saveRawResponse($model2, ['usage' => ['total_tokens' => 100]], 1);
            $storageManager->saveEvaluation($model2, ['overall_score' => 85, 'logic' => ['score' => 85], 'syntax' => ['score' => 85], 'output' => ['score' => 85]], 1);

            $storageManager->saveRawResponse($model3, ['usage' => ['total_tokens' => 100]], 1);
            $storageManager->saveEvaluation($model3, ['overall_score' => 95, 'logic' => ['score' => 95], 'syntax' => ['score' => 95], 'output' => ['score' => 95]], 1);

            $reporter = new HtmlReporter($storageManager);
            $reporter->generateReport($outputPath);

            $html = file_get_contents($outputPath);
            expect(strpos($html, 'Top Performers'))->not->toBeFalse();
            expect(strpos($html, '🥇'))->not->toBeFalse();
            expect(strpos($html, '🥈'))->not->toBeFalse();
            expect(strpos($html, '🥉'))->not->toBeFalse();

            removeDirectoryHtml($testPath);
        });

        it('includes score distribution classes', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $outputPath = $testPath . '/report.html';
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'provider');
            $storageManager = new StorageManager($testPath);

            $storageManager->saveRawResponse($model, ['usage' => ['total_tokens' => 100]], 1);
            $storageManager->saveEvaluation($model, ['overall_score' => 85, 'logic' => ['score' => 85], 'syntax' => ['score' => 85], 'output' => ['score' => 85]], 1);

            $reporter = new HtmlReporter($storageManager);
            $reporter->generateReport($outputPath);

            $html = file_get_contents($outputPath);
            expect(strpos($html, 'score-excellent'))->not->toBeFalse();

            removeDirectoryHtml($testPath);
        });
    });
});

function removeDirectoryHtml(string $path): void
{
    if (is_dir($path)) {
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath = $path . '/' . $item;
            if (is_dir($fullPath)) {
                removeDirectory($fullPath);
            } else {
                unlink($fullPath);
            }
        }
        rmdir($path);
    }
}
