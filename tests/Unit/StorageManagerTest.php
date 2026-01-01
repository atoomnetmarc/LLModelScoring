<?php

declare(strict_types=1);

use LLMScoring\Models\Model;
use LLMScoring\Storage\StorageManager;

describe('StorageManager', function () {
    describe('getModelPath', function () {
        it('returns correct path for model with question code', function () {
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager('/tmp/test', 'unittests');

            $path = $storageManager->getModelPath($model);

            expect($path)->toBe('/tmp/test/unittests/models/test_model');
        });

        it('sanitizes model IDs with special characters', function () {
            $model = new Model('meta-llama/llama-3.1-8b-instruct', 'Llama 3.1 8B', '0', '0', 4096, true, 1, 'Meta');
            $storageManager = new StorageManager('/tmp/test', 'unittests');

            $path = $storageManager->getModelPath($model);

            expect(strpos($path, '..'))->toBeFalse();
            expect(strpos($path, 'meta-llama_llama-3.1-8b-instruct'))->not->toBeFalse();
            expect(strpos($path, 'unittests'))->not->toBeFalse();
        });
    });

    describe('ensureModelDirectory', function () {
        it('creates directory when it does not exist', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath);

            $path = $storageManager->ensureModelDirectory($model);

            expect(is_dir($path))->toBeTrue();

            removeDirectory($testPath);
        });
    });

    describe('saveTestPrompt', function () {
        it('saves test prompt with correct format', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath);

            $path = $storageManager->saveTestPrompt($model, 'Write PHP code', 1);

            $content = json_decode(file_get_contents($path), true);

            expect($content['model_id'])->toBe('test/model');
            expect($content['model_name'])->toBe('Test Model');
            expect($content['test_number'])->toBe(1);
            expect($content['prompt'])->toBe('Write PHP code');
            expect(isset($content['timestamp']))->toBeTrue();

            removeDirectory($testPath);
        });
    });

    describe('saveRawResponse', function () {
        it('saves raw response with correct format', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('anthropic/claude-3-5-sonnet', 'Claude 3.5 Sonnet', '0', '0', 200000, true, 1, 'Anthropic');
            $storageManager = new StorageManager($testPath);

            $response = [
                'id' => 'test-response-id',
                'object' => 'chat.completion',
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Here is the PHP code.',
                        ],
                    ],
                ],
            ];

            $path = $storageManager->saveRawResponse($model, $response, 1);

            $content = json_decode(file_get_contents($path), true);

            expect($content['model_id'])->toBe('anthropic/claude-3-5-sonnet');
            expect($content['response'])->toBe($response);

            removeDirectory($testPath);
        });
    });

    describe('saveEvaluation', function () {
        it('saves evaluation with correct format', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath);

            $evaluation = [
                'logic_score' => 8,
                'syntax_score' => 9,
                'output_score' => 7,
                'total_score' => 8,
                'passed' => true,
            ];

            $path = $storageManager->saveEvaluation($model, $evaluation, 1);

            $content = json_decode(file_get_contents($path), true);

            expect($content['evaluation'])->toBe($evaluation);

            removeDirectory($testPath);
        });
    });

    describe('saveConversation and loadConversation', function () {
        it('saves and loads conversation', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath);

            $messages = [
                ['role' => 'user', 'content' => 'Write PHP code'],
                ['role' => 'assistant', 'content' => 'Here is the code'],
            ];

            $storageManager->saveConversation($model, $messages, 1);

            $loaded = $storageManager->loadConversation($model);

            expect($loaded)->not->toBeNull();
            expect($loaded['messages'])->toBe($messages);

            removeDirectory($testPath);
        });

        it('returns null when loading non-existent conversation', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('nonexistent/model', 'Nonexistent', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath);

            $result = $storageManager->loadConversation($model);

            expect($result)->toBeNull();

            removeDirectory($testPath);
        });
    });

    describe('isModelTested', function () {
        it('returns false when model has not been tested', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath);

            expect($storageManager->isModelTested($model))->toBeFalse();

            removeDirectory($testPath);
        });

        it('returns true when model has been tested', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath);

            $storageManager->saveRawResponse($model, ['test' => 'response'], 1);

            expect($storageManager->isModelTested($model))->toBeTrue();

            removeDirectory($testPath);
        });
    });

    describe('getTestedModelIds', function () {
        it('returns empty array when no models tested', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $storageManager = new StorageManager($testPath);

            $testedIds = $storageManager->getTestedModelIds();

            expect($testedIds)->toBeArray();
            expect(count($testedIds))->toBe(0);

            removeDirectory($testPath);
        });

        it('returns tested model IDs', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model1 = new Model('model/one', 'Model One', '0', '0', 4096, true, 1, 'provider');
            $model3 = new Model('model/three', 'Model Three', '0', '0', 4096, true, 1, 'provider');
            $storageManager = new StorageManager($testPath);

            $storageManager->saveRawResponse($model1, ['test' => 'response'], 1);
            $storageManager->saveRawResponse($model3, ['test' => 'response'], 1);

            $testedIds = $storageManager->getTestedModelIds();

            expect(count($testedIds))->toBe(2);
            expect(in_array('model_one', $testedIds))->toBeTrue();
            expect(in_array('model_three', $testedIds))->toBeTrue();

            removeDirectory($testPath);
        });

        it('returns tested model IDs even with non-01 numbered files', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('model/test', 'Test Model', '0', '0', 4096, true, 1, 'provider');
            $storageManager = new StorageManager($testPath);

            // Simulate a model with test number 14 (like google_gemma-3-12b-it_free)
            $storageManager->saveRawResponse($model, ['test' => 'response'], 14);

            $testedIds = $storageManager->getTestedModelIds();

            expect(count($testedIds))->toBe(1);
            expect(in_array('model_test', $testedIds))->toBeTrue();

            removeDirectory($testPath);
        });
    });

    describe('getLatestTestResult', function () {
        it('returns null when no test results exist', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath);

            $latest = $storageManager->getLatestTestResult($model);

            expect($latest)->toBeNull();

            removeDirectory($testPath);
        });

        it('returns latest test result', function () {
            $testPath = sys_get_temp_dir() . '/llmscoring_test_' . uniqid();
            $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 1, 'test-provider');
            $storageManager = new StorageManager($testPath);

            $storageManager->saveRawResponse($model, ['test' => 'response1'], 1);
            $storageManager->saveRawResponse($model, ['test' => 'response2'], 2);

            $latest = $storageManager->getLatestTestResult($model);

            expect($latest['response']['test'])->toBe('response2');

            removeDirectory($testPath);
        });
    });

    describe('getModelPathFromId', function () {
        it('returns correct path from model ID string with question code', function () {
            $storageManager = new StorageManager('/tmp/test', 'unittests');

            $path = $storageManager->getModelPathFromId('meta-llama/llama-3.1-8b-instruct');

            expect($path)->toBe('/tmp/test/unittests/models/meta-llama_llama-3.1-8b-instruct');
        });

        it('handles model IDs with colons with question code', function () {
            $storageManager = new StorageManager('/tmp/test', 'unittests');

            $path = $storageManager->getModelPathFromId('allenai_olmo-3.1-32b-think:free');

            expect($path)->toBe('/tmp/test/unittests/models/allenai_olmo-3.1-32b-think_free');
        });
    });
});

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
