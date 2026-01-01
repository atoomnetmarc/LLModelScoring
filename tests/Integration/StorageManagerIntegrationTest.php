<?php

declare(strict_types=1);

use LLMScoring\Models\Model;
use LLMScoring\Storage\StorageManager;

/**
 * Integration tests for StorageManager
 * Tests the full workflow of saving and loading model data
 */
describe('StorageManager Integration', function () {
    describe('saves complete test session for a model', function () {
        it('saves complete test session for a model', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_integration_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath, 'unittests');

            $prompt = 'Write a PHP script that counts from 1 to 10';
            $response = [
                'id' => 'resp-123',
                'choices' => [
                    [
                        'message' => [
                            'content' => '<?php for($i=1;$i<=10;$i++){echo "$i ";}',
                        ],
                    ],
                ],
            ];
            $evaluation = [
                'logic_score' => 10,
                'syntax_score' => 10,
                'output_score' => 10,
                'total_score' => 10.0,
                'passed' => true,
            ];

            $storageManager->saveTestPrompt($model, $prompt, 1);
            $storageManager->saveRawResponse($model, $response, 1);
            $storageManager->saveEvaluation($model, $evaluation, 1);

            expect(file_exists($testPath . '/unittests/models/test_model/01_test_prompt.json'))->toBeTrue();
            expect(file_exists($testPath . '/unittests/models/test_model/01_raw_response.json'))->toBeTrue();
            expect(file_exists($testPath . '/unittests/models/test_model/01_evaluation.json'))->toBeTrue();
            expect($storageManager->isModelTested($model))->toBeTrue();

            removeDirectory($testPath);
        });
    });

    describe('handles multiple test sessions for same model', function () {
        it('handles multiple test sessions for same model', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_integration_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath, 'unittests');

            $storageManager->saveRawResponse($model, ['test' => 'response1'], 1);
            $storageManager->saveRawResponse($model, ['test' => 'response2'], 2);

            expect(file_exists($testPath . '/unittests/models/test_model/01_raw_response.json'))->toBeTrue();
            expect(file_exists($testPath . '/unittests/models/test_model/02_raw_response.json'))->toBeTrue();

            $latest = $storageManager->getLatestTestResult($model);
            expect($latest['response']['test'])->toBe('response2');

            removeDirectory($testPath);
        });
    });

    describe('persists data across StorageManager instances', function () {
        it('persists data across StorageManager instances', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_integration_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');

            $storageManager1 = new StorageManager($testPath, 'unittests');
            $storageManager1->saveRawResponse($model, ['test' => 'data'], 1);

            $storageManager2 = new StorageManager($testPath, 'unittests');
            $latest = $storageManager2->getLatestTestResult($model);

            expect($latest['response']['test'])->toBe('data');

            removeDirectory($testPath);
        });
    });

    describe('handles special characters in model IDs', function () {
        it('handles special characters in model IDs', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_integration_' . uniqid();
            $model = new Model('openai/gpt-4-turbo', 'GPT-4 Turbo', '0', '0', 4096, true, 1, 'OpenAI');
            $storageManager = new StorageManager($testPath, 'unittests');

            $storageManager->saveRawResponse($model, ['test' => 'data'], 1);

            expect($storageManager->isModelTested($model))->toBeTrue();

            removeDirectory($testPath);
        });
    });

    describe('tracks multiple tested models', function () {
        it('tracks multiple tested models', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_integration_' . uniqid();
            $model1 = new Model('model/one', 'Model One', '0', '0', 4096, true, 1, 'provider');
            $model2 = new Model('model/two', 'Model Two', '0', '0', 4096, true, 1, 'provider');
            $model3 = new Model('model/three', 'Model Three', '0', '0', 4096, true, 1, 'provider');
            $storageManager = new StorageManager($testPath, 'unittests');

            $storageManager->saveRawResponse($model1, ['test' => 'data'], 1);
            $storageManager->saveRawResponse($model3, ['test' => 'data'], 1);

            $testedIds = $storageManager->getTestedModelIds();

            expect(count($testedIds))->toBe(2);
            expect(in_array('model_one', $testedIds))->toBeTrue();
            expect(in_array('model_three', $testedIds))->toBeTrue();
            expect(in_array('model_two', $testedIds))->toBeFalse();

            removeDirectory($testPath);
        });
    });
});

if (!function_exists('removeDirectory')) {
    function removeDirectory(string $path): void
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
}
