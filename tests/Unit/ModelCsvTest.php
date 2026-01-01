<?php

declare(strict_types=1);

use LLMScoring\Models\Model;
use LLMScoring\Models\ModelCollection;

describe('Model CSV', function () {
    describe('toCsvRow', function () {
        it('converts model to CSV row format', function () {
            $model = new Model(
                'test/model',
                'Test Model',
                '0.0001',
                '0.0002',
                4096,
                true,
                1,
                'TestProvider'
            );

            $row = $model->toCsvRow();

            expect($row['model_id'])->toBe('test/model');
            expect($row['name'])->toBe('Test Model');
            expect($row['pricing_input'])->toBe('0.0001');
            expect($row['pricing_output'])->toBe('0.0002');
            expect($row['context_length'])->toBe(4096);
            expect($row['enabled'])->toBe('1');
            expect($row['priority'])->toBe(1);
            expect($row['provider'])->toBe('TestProvider');
        });

        it('handles null values in CSV row', function () {
            $model = new Model(
                'test/model',
                'Test Model',
                null,
                null,
                null,
                false,
                0,
                null
            );

            $row = $model->toCsvRow();

            expect($row['model_id'])->toBe('test/model');
            expect($row['pricing_input'])->toBe('');
            expect($row['pricing_output'])->toBe('');
            expect($row['context_length'])->toBe('');
            expect($row['enabled'])->toBe('0');
            expect($row['provider'])->toBe('');
        });
    });

    describe('fromCsvRow', function () {
        it('creates model from CSV row', function () {
            $row = [
                'model_id' => 'test/model',
                'name' => 'Test Model',
                'pricing_input' => '0.0001',
                'pricing_output' => '0.0002',
                'context_length' => '4096',
                'enabled' => '1',
                'priority' => '5',
                'provider' => 'TestProvider',
            ];

            $model = Model::fromCsvRow($row);

            expect($model->getId())->toBe('test/model');
            expect($model->getName())->toBe('Test Model');
            expect($model->getPricingInput())->toBe('0.0001');
            expect($model->getPricingOutput())->toBe('0.0002');
            expect($model->getContextLength())->toBe(4096);
            expect($model->isEnabled())->toBeTrue();
            expect($model->getPriority())->toBe(5);
            expect($model->getProvider())->toBe('TestProvider');
        });

        it('handles disabled model from CSV', function () {
            $row = [
                'model_id' => 'test/model',
                'name' => 'Test Model',
                'enabled' => '0',
            ];

            $model = Model::fromCsvRow($row);

            expect($model->isEnabled())->toBeFalse();
        });

        it('uses default values for missing fields', function () {
            $row = [];

            $model = Model::fromCsvRow($row);

            expect($model->getId())->toBe('');
            expect($model->getName())->toBe('');
            expect($model->isEnabled())->toBeTrue();
            expect($model->getPriority())->toBe(0);
        });
    });

    describe('isFree', function () {
        it('returns true for free model', function () {
            $model = new Model(
                'free/model',
                'Free Model',
                '0',
                '0',
                null
            );

            expect($model->isFree())->toBeTrue();
        });

        it('returns true for free model with 0.0', function () {
            $model = new Model(
                'free/model',
                'Free Model',
                '0.0',
                '0.0',
                null
            );

            expect($model->isFree())->toBeTrue();
        });

        it('returns false for paid model', function () {
            $model = new Model(
                'paid/model',
                'Paid Model',
                '0.0001',
                '0.0002',
                null
            );

            expect($model->isFree())->toBeFalse();
        });

        it('handles null pricing', function () {
            $model = new Model(
                'unknown/model',
                'Unknown Model',
                null,
                null,
                null
            );

            expect($model->isFree())->toBeTrue();
        });
    });
});

describe('ModelCollection CSV operations', function () {
    describe('filterEnabled', function () {
        it('filters enabled models', function () {
            $enabled = new Model('enabled/model', 'Enabled', null, null, null, true);
            $disabled = new Model('disabled/model', 'Disabled', null, null, null, false);

            $collection = new ModelCollection([$enabled, $disabled]);
            $filtered = $collection->filterEnabled(true);

            expect($filtered->count())->toBe(1);
            expect($filtered->get('enabled/model'))->not->toBeNull();
            expect($filtered->get('disabled/model'))->toBeNull();
        });

        it('filters disabled models', function () {
            $enabled = new Model('enabled/model', 'Enabled', null, null, null, true);
            $disabled = new Model('disabled/model', 'Disabled', null, null, null, false);

            $collection = new ModelCollection([$enabled, $disabled]);
            $filtered = $collection->filterEnabled(false);

            expect($filtered->count())->toBe(1);
            expect($filtered->get('enabled/model'))->toBeNull();
            expect($filtered->get('disabled/model'))->not->toBeNull();
        });
    });

    describe('filterFree', function () {
        it('filters free models only', function () {
            $free = new Model('free/model', 'Free', '0', '0', null);
            $paid = new Model('paid/model', 'Paid', '0.0001', '0.0002', null);

            $collection = new ModelCollection([$free, $paid]);
            $filtered = $collection->filterFree();

            expect($filtered->count())->toBe(1);
            expect($filtered->get('free/model'))->not->toBeNull();
            expect($filtered->get('paid/model'))->toBeNull();
        });
    });

    describe('sortByPriority', function () {
        it('sorts models by priority descending', function () {
            $low = new Model('low/model', 'Low', null, null, null, true, 1);
            $high = new Model('high/model', 'High', null, null, null, true, 10);
            $medium = new Model('medium/model', 'Medium', null, null, null, true, 5);

            $collection = new ModelCollection([$low, $high, $medium]);
            $sorted = $collection->sortByPriority();

            $ids = $sorted->getIds();
            expect($ids[0])->toBe('high/model');
            expect($ids[1])->toBe('medium/model');
            expect($ids[2])->toBe('low/model');
        });
    });
});
