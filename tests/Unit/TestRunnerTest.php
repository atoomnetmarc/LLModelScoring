<?php

declare(strict_types=1);

use LLMScoring\Client\OpenRouterClientInterface;
use LLMScoring\Models\Model;
use LLMScoring\Models\ModelCollection;
use LLMScoring\Runner\TestRunner;

/**
 * Mock class for OpenRouterClientInterface testing
 */
class MockOpenRouterClient implements OpenRouterClientInterface
{
    public array $calls = [];

    public function hasApiKey(): bool
    {
        return true;
    }

    public function fetchModels(): ModelCollection
    {
        return new ModelCollection([]);
    }

    public function sendChatCompletion(string $modelId, array $messages, array $options = []): array
    {
        $this->calls[] = ['method' => 'sendChatCompletion', 'modelId' => $modelId, 'messages' => $messages];
        return [
            'id' => 'test-response-id',
            'choices' => [
                [
                    'message' => [
                        'content' => 'Test response content'
                    ]
                ]
            ]
        ];
    }
}

describe('TestRunner', function () {
    describe('loadModelsFromCsv', function () {
        it('throws exception when CSV file does not exist', function () {
            $runner = new TestRunner(new MockOpenRouterClient());

            expect(fn() => $runner->loadModelsFromCsv('nonexistent.csv'))
                ->toThrow(\RuntimeException::class, 'CSV file not found');
        });

        it('loads models from CSV file', function () {
            // Create a temporary CSV file
            $csvContent = <<<CSV
model_id,name,pricing_input,pricing_output,context_length,enabled,priority,provider
test/model-1,Test Model 1,0,0,4096,1,5,Provider1
test/model-2,Test Model 2,0.0001,0.0002,8192,1,3,Provider2
test/model-3,Test Model 3,0,0,2048,0,10,Provider3
CSV;

            $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_') . '.csv';
            file_put_contents($tempFile, $csvContent);

            try {
                $runner = new TestRunner(new MockOpenRouterClient());
                $models = $runner->loadModelsFromCsv($tempFile);

                expect($models->count())->toBe(3);
                expect($models->get('test/model-1'))->not->toBeNull();
                expect($models->get('test/model-2'))->not->toBeNull();
                expect($models->get('test/model-3'))->not->toBeNull();
            } finally {
                unlink($tempFile);
            }
        });

        it('filters enabled models only', function () {
            $csvContent = <<<CSV
model_id,name,pricing_input,pricing_output,context_length,enabled,priority,provider
enabled/model,Enabled Model,0,0,4096,1,5,Provider
disabled/model,Disabled Model,0,0,4096,0,3,Provider
CSV;

            $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_') . '.csv';
            file_put_contents($tempFile, $csvContent);

            try {
                $runner = new TestRunner(new MockOpenRouterClient());
                $models = $runner->loadModelsFromCsv($tempFile, enabledOnly: true);

                expect($models->count())->toBe(1);
                expect($models->get('enabled/model'))->not->toBeNull();
                expect($models->get('disabled/model'))->toBeNull();
            } finally {
                unlink($tempFile);
            }
        });

        it('filters free models only', function () {
            $csvContent = <<<CSV
model_id,name,pricing_input,pricing_output,context_length,enabled,priority,provider
free/model,Free Model,0,0,4096,1,5,Provider
paid/model,Paid Model,0.0001,0.0002,8192,1,3,Provider
CSV;

            $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_') . '.csv';
            file_put_contents($tempFile, $csvContent);

            try {
                $runner = new TestRunner(new MockOpenRouterClient());
                $models = $runner->loadModelsFromCsv($tempFile, freeOnly: true);

                expect($models->count())->toBe(1);
                expect($models->get('free/model'))->not->toBeNull();
                expect($models->get('paid/model'))->toBeNull();
            } finally {
                unlink($tempFile);
            }
        });

        it('sorts models by priority descending', function () {
            $csvContent = <<<CSV
model_id,name,pricing_input,pricing_output,context_length,enabled,priority,provider
low/model,Low Priority,0,0,4096,1,1,Provider
high/model,High Priority,0,0,4096,1,10,Provider
medium/model,Medium Priority,0,0,4096,1,5,Provider
CSV;

            $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_') . '.csv';
            file_put_contents($tempFile, $csvContent);

            try {
                $runner = new TestRunner(new MockOpenRouterClient());
                $models = $runner->loadModelsFromCsv($tempFile);

                $ids = $models->getIds();
                expect($ids[0])->toBe('high/model');
                expect($ids[1])->toBe('medium/model');
                expect($ids[2])->toBe('low/model');
            } finally {
                unlink($tempFile);
            }
        });

        it('applies both enabled and free filters', function () {
            $csvContent = <<<CSV
model_id,name,pricing_input,pricing_output,context_length,enabled,priority,provider
free-enabled,Free Enabled,0,0,4096,1,5,Provider
free-disabled,Free Disabled,0,0,4096,0,3,Provider
paid-enabled,Paid Enabled,0.0001,0.0002,8192,1,10,Provider
paid-disabled,Paid Disabled,0.0001,0.0002,8192,0,1,Provider
CSV;

            $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_') . '.csv';
            file_put_contents($tempFile, $csvContent);

            try {
                $runner = new TestRunner(new MockOpenRouterClient());
                $models = $runner->loadModelsFromCsv($tempFile, enabledOnly: true, freeOnly: true);

                expect($models->count())->toBe(1);
                expect($models->get('free-enabled'))->not->toBeNull();
                expect($models->get('free-disabled'))->toBeNull();
                expect($models->get('paid-enabled'))->toBeNull();
                expect($models->get('paid-disabled'))->toBeNull();
            } finally {
                unlink($tempFile);
            }
        });
    });

    describe('testModel', function () {
        it('sends prompt to model and returns response', function () {
            $model = new Model(
                'test/model',
                'Test Model',
                '0',
                '0',
                4096,
                true,
                5,
                'Provider'
            );

            $mockClient = new MockOpenRouterClient();
            $runner = new TestRunner($mockClient);

            $result = $runner->testModel($model, 'Test prompt');

            expect($result['model_id'])->toBe('test/model');
            expect($result['model_name'])->toBe('Test Model');
            expect($result['prompt'])->toBe('Test prompt');
            expect($result['response']['id'])->toBe('test-response-id');
            expect($result['timestamp'])->not->toBeEmpty();

            // Verify the client was called correctly
            expect(count($mockClient->calls))->toBe(1);
            expect($mockClient->calls[0]['modelId'])->toBe('test/model');
            expect($mockClient->calls[0]['messages'])->toEqual([
                ['role' => 'user', 'content' => 'Test prompt']
            ]);
        });
    });

    describe('getClient', function () {
        it('returns the OpenRouter client', function () {
            $mockClient = new MockOpenRouterClient();
            $runner = new TestRunner($mockClient);

            expect($runner->getClient())->toBe($mockClient);
        });
    });
});
