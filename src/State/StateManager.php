<?php

declare(strict_types=1);

namespace LLMScoring\State;

use LLMScoring\Models\Model;
use LLMScoring\Storage\ModelPathNormalizer;

/**
 * Manages evaluation state for models
 */
class StateManager
{
    private string $dataDir;
    private string $modelsDir;
    private array $states = [];

    public function __construct(?string $dataDir = null, string $questionCode = 'default')
    {
        $this->dataDir = $dataDir ?? dirname(__DIR__, 2) . '/data';
        // Structure: data/{questionCode}/models/
        $this->modelsDir = $this->dataDir . '/' . $this->normalizeQuestionCode($questionCode) . '/models';
        $this->ensureDataDirExists();
    }

    /**
     * Normalize a question code to a safe directory name
     *
     * @param string $questionCode The question code to normalize
     * @return string A safe directory name
     */
    private function normalizeQuestionCode(string $questionCode): string
    {
        // Characters that are not safe in directory names
        $unsafeChars = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];
        $safe = str_replace($unsafeChars, '_', $questionCode);

        // Limit length to avoid filesystem limitations
        if (strlen($safe) > 100) {
            $safe = substr($safe, 0, 100);
        }

        return $safe;
    }

    /**
     * Ensure the data directory exists
     */
    private function ensureDataDirExists(): void
    {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    /**
     * Get the state file path for a model
     * Handles model IDs with slashes by using a safe directory name
     */
    private function getStateFilePath(string $modelId): string
    {
        return ModelPathNormalizer::getModelPath($this->modelsDir, $modelId) . '/state.json';
    }

    /**
     * Get the models directory path
     */
    public function getModelsDir(): string
    {
        return $this->modelsDir;
    }

    /**
     * Get or create state for a model
     */
    public function getState(Model $model): EvaluationState
    {
        $modelId = $model->getId();

        if (isset($this->states[$modelId])) {
            return $this->states[$modelId];
        }

        $state = $this->loadState($modelId, $model);
        $this->states[$modelId] = $state;

        return $state;
    }

    /**
     * Load state from file or create new
     */
    private function loadState(string $modelId, ?Model $model = null): EvaluationState
    {
        $stateFilePath = $this->getStateFilePath($modelId);

        if (file_exists($stateFilePath)) {
            $content = file_get_contents($stateFilePath);
            $data = json_decode($content, true);

            if ($data !== null) {
                return EvaluationState::fromArray($data);
            }
        }

        // Create new state for this model
        return new EvaluationState(
            $modelId,
            $model?->getName() ?? $modelId,
            EvaluationState::STATUS_PENDING
        );
    }

    /**
     * Save state to file
     */
    public function saveState(EvaluationState $state): void
    {
        $modelId = $state->getModelId();
        $stateFilePath = $this->getStateFilePath($modelId);
        $modelDir = dirname($stateFilePath);

        if (!is_dir($modelDir)) {
            mkdir($modelDir, 0755, true);
        }

        $json = json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($stateFilePath, $json);

        $this->states[$modelId] = $state;
    }

    /**
     * Mark a model as in progress
     */
    public function startModel(Model $model): EvaluationState
    {
        $state = $this->getState($model);
        $state->start();
        $this->saveState($state);

        return $state;
    }

    /**
     * Mark a model as completed
     */
    public function completeModel(Model $model, array $metadata = []): EvaluationState
    {
        $state = $this->getState($model);
        $state->complete($metadata);
        $this->saveState($state);

        return $state;
    }

    /**
     * Mark a model as failed
     */
    public function failModel(Model $model, string $errorMessage): EvaluationState
    {
        $state = $this->getState($model);
        $state->fail($errorMessage);
        $this->saveState($state);

        return $state;
    }

    /**
     * Get all model states
     */
    public function getAllStates(): array
    {
        $modelsDir = $this->getModelsDir();

        if (!is_dir($modelsDir)) {
            return [];
        }

        $states = [];
        $items = scandir($modelsDir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $dirPath = $modelsDir . '/' . $item;
            if (!is_dir($dirPath)) {
                continue;
            }

            $stateFilePath = $dirPath . '/state.json';

            if (file_exists($stateFilePath)) {
                $content = file_get_contents($stateFilePath);
                $data = json_decode($content, true);

                if ($data !== null && isset($data['model_id'])) {
                    // Use the original model_id from the state file (it's already correct)
                    $modelId = $data['model_id'];
                    $states[$modelId] = EvaluationState::fromArray($data);
                }
            }
        }

        return $states;
    }

    /**
     * Get progress summary
     *
     * @param int|null $totalModels Optional total count from CSV (provides accurate progress)
     */
    public function getProgressSummary(?int $totalModels = null): array
    {
        $states = $this->getAllStates();

        $total = $totalModels ?? count($states);
        $completed = 0;
        $failed = 0;
        $inProgress = 0;
        $pending = 0;

        foreach ($states as $state) {
            switch ($state->getStatus()) {
                case EvaluationState::STATUS_COMPLETED:
                    $completed++;
                    break;
                case EvaluationState::STATUS_FAILED:
                    $failed++;
                    break;
                case EvaluationState::STATUS_IN_PROGRESS:
                    $inProgress++;
                    break;
                default:
                    $pending++;
            }
        }

        // If totalModels is provided, pending is the remainder
        if ($totalModels !== null) {
            $pending = $totalModels - $completed - $failed - $inProgress;
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'in_progress' => $inProgress,
            'pending' => max(0, $pending),
            'percent_complete' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Get list of completed model IDs
     */
    public function getCompletedModelIds(): array
    {
        $states = $this->getAllStates();
        $completedIds = [];

        foreach ($states as $modelId => $state) {
            if ($state->isCompleted()) {
                $completedIds[] = $modelId;
            }
        }

        return $completedIds;
    }

    /**
     * Check if a model has been completed
     */
    public function isModelCompleted(string $modelId): bool
    {
        $stateFilePath = $this->getStateFilePath($modelId);

        if (!file_exists($stateFilePath)) {
            return false;
        }

        $content = file_get_contents($stateFilePath);
        $data = json_decode($content, true);

        if ($data === null) {
            return false;
        }

        $state = EvaluationState::fromArray($data);
        return $state->isCompleted();
    }

    /**
     * Reset state for a specific model
     */
    public function resetModel(Model $model): EvaluationState
    {
        $modelId = $model->getId();
        $stateFilePath = $this->getStateFilePath($modelId);

        if (file_exists($stateFilePath)) {
            unlink($stateFilePath);
        }

        unset($this->states[$modelId]);

        return new EvaluationState(
            $modelId,
            $model->getName(),
            EvaluationState::STATUS_PENDING
        );
    }

    /**
     * Reset all states
     */
    public function resetAll(): void
    {
        $modelsDir = $this->getModelsDir();

        if (is_dir($modelsDir)) {
            $this->recursiveDelete($modelsDir);
        }

        $this->states = [];
        $this->ensureDataDirExists();
    }

    /**
     * Recursively delete a directory
     */
    private function recursiveDelete(string $path): void
    {
        if (is_dir($path)) {
            $items = array_diff(scandir($path), ['.', '..']);

            foreach ($items as $item) {
                $this->recursiveDelete($path . '/' . $item);
            }

            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
}
