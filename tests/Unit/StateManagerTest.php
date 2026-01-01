<?php

declare(strict_types=1);

use LLMScoring\Models\Model;
use LLMScoring\State\EvaluationState;
use LLMScoring\State\StateManager;

describe('EvaluationState', function () {
    describe('creation', function () {
        it('creates a pending state by default', function () {
            $state = new EvaluationState('model/1', 'Model 1');

            expect($state->getModelId())->toBe('model/1');
            expect($state->getModelName())->toBe('Model 1');
            expect($state->getStatus())->toBe(EvaluationState::STATUS_PENDING);
            expect($state->getStartedAt())->toBeNull();
            expect($state->getCompletedAt())->toBeNull();
        });

        it('creates state from array', function () {
            $data = [
                'model_id' => 'model/2',
                'model_name' => 'Model 2',
                'status' => EvaluationState::STATUS_COMPLETED,
                'started_at' => '2025-01-01T10:00:00Z',
                'completed_at' => '2025-01-01T10:05:00Z',
                'error_message' => null,
                'metadata' => ['prompt' => 'test'],
            ];

            $state = EvaluationState::fromArray($data);

            expect($state->getModelId())->toBe('model/2');
            expect($state->getModelName())->toBe('Model 2');
            expect($state->getStatus())->toBe(EvaluationState::STATUS_COMPLETED);
            expect($state->getStartedAt())->toBe('2025-01-01T10:00:00Z');
            expect($state->getCompletedAt())->toBe('2025-01-01T10:05:00Z');
        });

        it('converts to array', function () {
            $state = new EvaluationState(
                'model/3',
                'Model 3',
                EvaluationState::STATUS_IN_PROGRESS,
                '2025-01-01T10:00:00Z'
            );

            $array = $state->toArray();

            expect($array['model_id'])->toBe('model/3');
            expect($array['model_name'])->toBe('Model 3');
            expect($array['status'])->toBe(EvaluationState::STATUS_IN_PROGRESS);
            expect($array['started_at'])->toBe('2025-01-01T10:00:00Z');
        });
    });

    describe('state transitions', function () {
        it('starts a model evaluation', function () {
            $state = new EvaluationState('model/1', 'Model 1');

            $state->start();

            expect($state->getStatus())->toBe(EvaluationState::STATUS_IN_PROGRESS);
            expect($state->getStartedAt())->not->toBeNull();
        });

        it('completes a model evaluation', function () {
            $state = new EvaluationState('model/1', 'Model 1');
            $state->start();

            $state->complete(['score' => 85]);

            expect($state->getStatus())->toBe(EvaluationState::STATUS_COMPLETED);
            expect($state->getCompletedAt())->not->toBeNull();
            expect($state->getMetadata())->toHaveKey('score');
            expect($state->getMetadata()['score'])->toBe(85);
        });

        it('fails a model evaluation', function () {
            $state = new EvaluationState('model/1', 'Model 1');
            $state->start();

            $state->fail('API error');

            expect($state->getStatus())->toBe(EvaluationState::STATUS_FAILED);
            expect($state->getCompletedAt())->not->toBeNull();
            expect($state->getErrorMessage())->toBe('API error');
        });
    });

    describe('status checks', function () {
        it('checks pending status correctly', function () {
            $state = new EvaluationState('model/1', 'Model 1');

            expect($state->isPending())->toBeTrue();
            expect($state->isInProgress())->toBeFalse();
            expect($state->isCompleted())->toBeFalse();
            expect($state->isFailed())->toBeFalse();
            expect($state->isFinished())->toBeFalse();
        });

        it('checks completed status correctly', function () {
            $state = new EvaluationState('model/1', 'Model 1');
            $state->complete();

            expect($state->isPending())->toBeFalse();
            expect($state->isInProgress())->toBeFalse();
            expect($state->isCompleted())->toBeTrue();
            expect($state->isFailed())->toBeFalse();
            expect($state->isFinished())->toBeTrue();
        });

        it('checks failed status correctly', function () {
            $state = new EvaluationState('model/1', 'Model 1');
            $state->fail('error');

            expect($state->isPending())->toBeFalse();
            expect($state->isInProgress())->toBeFalse();
            expect($state->isCompleted())->toBeFalse();
            expect($state->isFailed())->toBeTrue();
            expect($state->isFinished())->toBeTrue();
        });
    });
});

describe('StateManager', function () {
    it('creates a new state for a model', function () {
        $tempDir = createTempDir();
        $stateManager = new StateManager($tempDir, 'unittests');
        $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 5);

        $state = $stateManager->getState($model);

        expect($state->getModelId())->toBe('test/model');
        expect($state->getModelName())->toBe('Test Model');
        expect($state->isPending())->toBeTrue();

        cleanupTempDir($tempDir);
    });

    it('starts a model evaluation', function () {
        $tempDir = createTempDir();
        $stateManager = new StateManager($tempDir, 'unittests');
        $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 5);

        $state = $stateManager->startModel($model);

        expect($state->isInProgress())->toBeTrue();
        expect($state->getStartedAt())->not->toBeNull();

        // Verify state is persisted
        $loadedState = $stateManager->getState($model);
        expect($loadedState->isInProgress())->toBeTrue();

        cleanupTempDir($tempDir);
    });

    it('completes a model evaluation', function () {
        $tempDir = createTempDir();
        $stateManager = new StateManager($tempDir, 'unittests');
        $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 5);

        $stateManager->completeModel($model, ['score' => 90]);

        $state = $stateManager->getState($model);
        expect($state->isCompleted())->toBeTrue();
        expect($state->getMetadata()['score'])->toBe(90);

        cleanupTempDir($tempDir);
    });

    it('fails a model evaluation', function () {
        $tempDir = createTempDir();
        $stateManager = new StateManager($tempDir, 'unittests');
        $model = new Model('test/model', 'Test Model', '0', '0', 4096, true, 5);

        $stateManager->failModel($model, 'Connection timeout');

        $state = $stateManager->getState($model);
        expect($state->isFailed())->toBeTrue();
        expect($state->getErrorMessage())->toBe('Connection timeout');

        cleanupTempDir($tempDir);
    });

    it('gets progress summary', function () {
        $tempDir = createTempDir();
        $stateManager = new StateManager($tempDir, 'unittests');

        // Create some models and complete/fail them
        $model1 = new Model('model/1', 'Model 1', '0', '0', 4096, true, 5);
        $model2 = new Model('model/2', 'Model 2', '0', '0', 4096, true, 5);
        $model3 = new Model('model/3', 'Model 3', '0', '0', 4096, true, 5);
        $model4 = new Model('model/4', 'Model 4', '0', '0', 4096, true, 5);

        $stateManager->completeModel($model1);
        $stateManager->completeModel($model2);
        $stateManager->failModel($model3, 'error');
        $stateManager->startModel($model4);

        $progress = $stateManager->getProgressSummary();

        expect($progress['total'])->toBe(4);
        expect($progress['completed'])->toBe(2);
        expect($progress['failed'])->toBe(1);
        expect($progress['in_progress'])->toBe(1);
        expect($progress['pending'])->toBe(0);
        expect($progress['percent_complete'])->toBe(50.0);

        cleanupTempDir($tempDir);
    });

    it('gets completed model IDs', function () {
        $tempDir = createTempDir();
        $stateManager = new StateManager($tempDir, 'unittests');

        $model1 = new Model('model/1', 'Model 1', '0', '0', 4096, true, 5);
        $model2 = new Model('model/2', 'Model 2', '0', '0', 4096, true, 5);
        $model3 = new Model('model/3', 'Model 3', '0', '0', 4096, true, 5);

        $stateManager->completeModel($model1);
        $stateManager->failModel($model2, 'error');
        // model3 is pending

        $completedIds = $stateManager->getCompletedModelIds();

        expect(count($completedIds))->toBe(1);
        expect($completedIds[0])->toBe('model/1');

        cleanupTempDir($tempDir);
    });

    it('checks if model is completed', function () {
        $tempDir = createTempDir();
        $stateManager = new StateManager($tempDir, 'unittests');
        $model = new Model('model/1', 'Model 1', '0', '0', 4096, true, 5);

        expect($stateManager->isModelCompleted('model/1'))->toBeFalse();

        $stateManager->completeModel($model);

        expect($stateManager->isModelCompleted('model/1'))->toBeTrue();

        cleanupTempDir($tempDir);
    });

    it('resets a model state', function () {
        $tempDir = createTempDir();
        $stateManager = new StateManager($tempDir, 'unittests');
        $model = new Model('model/1', 'Model 1', '0', '0', 4096, true, 5);

        $stateManager->completeModel($model);
        expect($stateManager->isModelCompleted('model/1'))->toBeTrue();

        $stateManager->resetModel($model);

        $state = $stateManager->getState($model);
        expect($state->isPending())->toBeTrue();

        cleanupTempDir($tempDir);
    });

    it('resets all states', function () {
        $tempDir = createTempDir();
        $stateManager = new StateManager($tempDir, 'unittests');

        $model1 = new Model('model/1', 'Model 1', '0', '0', 4096, true, 5);
        $model2 = new Model('model/2', 'Model 2', '0', '0', 4096, true, 5);

        $stateManager->completeModel($model1);
        $stateManager->completeModel($model2);

        $stateManager->resetAll();

        expect($stateManager->getProgressSummary()['total'])->toBe(0);

        cleanupTempDir($tempDir);
    });

    it('loads existing state from file', function () {
        $tempDir = createTempDir();
        $stateManager1 = new StateManager($tempDir, 'unittests');
        $model = new Model('model/1', 'Model 1', '0', '0', 4096, true, 5);

        // Create and save state
        $stateManager1->completeModel($model);

        // Create new StateManager to simulate fresh load
        $stateManager2 = new StateManager($tempDir, 'unittests');
        $state = $stateManager2->getState($model);

        expect($state->isCompleted())->toBeTrue();

        cleanupTempDir($tempDir);
    });
});

/**
 * Helper function to create a temp directory for testing
 */
function createTempDir(): string
{
    $tempDir = tempnam(sys_get_temp_dir(), 'state_test_');
    unlink($tempDir);
    // Create models directory with unittests subdirectory for question code support
    mkdir($tempDir . '/models/unittests', 0755, true);
    return $tempDir;
}

/**
 * Helper function to clean up temp directory
 */
function cleanupTempDir(string $path): void
{
    if (is_dir($path)) {
        $items = array_diff(scandir($path), ['.', '..']);
        foreach ($items as $item) {
            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                cleanupTempDir($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        rmdir($path);
    }
}
