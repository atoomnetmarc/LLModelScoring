<?php

declare(strict_types=1);

namespace LLMScoring\Storage;

use LLMScoring\Models\Model;

/**
 * StorageManager handles saving and loading model test data
 */
class StorageManager
{
    private string $storagePath;
    private string $questionCode;

    public function __construct(?string $storagePath = null, string $questionCode = 'default')
    {
        // Structure: data/{questionCode}/models/
        $basePath = $storagePath ?? dirname(__DIR__, 2) . '/data';
        $this->storagePath = $basePath . '/' . $this->normalizeQuestionCode($questionCode) . '/models';
        $this->questionCode = $questionCode;
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
     * Get the current question code
     *
     * @return string The question code
     */
    public function getQuestionCode(): string
    {
        return $this->questionCode;
    }

    /**
     * Get the storage path for a specific model
     */
    public function getModelPath(Model $model): string
    {
        return ModelPathNormalizer::getModelPath($this->storagePath, $model->getId());
    }

    /**
     * Ensure the model directory exists
     */
    public function ensureModelDirectory(Model $model): string
    {
        $path = $this->getModelPath($model);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        return $path;
    }

    /**
     * Save a test prompt to the model directory
     */
    public function saveTestPrompt(Model $model, string $prompt, int $testNumber = 1): string
    {
        $path = $this->ensureModelDirectory($model);
        $filename = sprintf('%02d_test_prompt.json', $testNumber);
        $fullPath = $path . '/' . $filename;

        $data = [
            'model_id' => $model->getId(),
            'model_name' => $model->getName(),
            'test_number' => $testNumber,
            'prompt' => $prompt,
            'timestamp' => date('c'),
        ];

        file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $fullPath;
    }

    /**
     * Save a raw API response to the model directory
     */
    public function saveRawResponse(Model $model, array $response, int $testNumber = 1): string
    {
        $path = $this->ensureModelDirectory($model);
        $filename = sprintf('%02d_raw_response.json', $testNumber);
        $fullPath = $path . '/' . $filename;

        $data = [
            'model_id' => $model->getId(),
            'model_name' => $model->getName(),
            'test_number' => $testNumber,
            'response' => $response,
            'timestamp' => date('c'),
        ];

        file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $fullPath;
    }

    /**
     * Save an evaluation result to the model directory
     */
    public function saveEvaluation(Model $model, array $evaluation, int $testNumber = 1): string
    {
        $path = $this->ensureModelDirectory($model);
        $filename = sprintf('%02d_evaluation.json', $testNumber);
        $fullPath = $path . '/' . $filename;

        $data = [
            'model_id' => $model->getId(),
            'model_name' => $model->getName(),
            'test_number' => $testNumber,
            'evaluation' => $evaluation,
            'timestamp' => date('c'),
        ];

        file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $fullPath;
    }

    /**
     * Save conversation history
     */
    public function saveConversation(Model $model, array $messages, int $testNumber = 1): string
    {
        $path = $this->ensureModelDirectory($model);
        $filename = 'conversation.json';
        $fullPath = $path . '/' . $filename;

        $data = [
            'model_id' => $model->getId(),
            'model_name' => $model->getName(),
            'test_number' => $testNumber,
            'messages' => $messages,
            'last_updated' => date('c'),
        ];

        file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $fullPath;
    }

    /**
     * Load conversation history
     */
    public function loadConversation(Model $model): ?array
    {
        $path = $this->getModelPath($model);
        $fullPath = $path . '/conversation.json';

        if (!file_exists($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        return json_decode($content, true);
    }

    /**
     * Check if a model has been tested
     */
    public function isModelTested(Model $model): bool
    {
        $path = $this->getModelPath($model);
        $rawResponsePath = $path . '/01_raw_response.json';
        return file_exists($rawResponsePath);
    }

    /**
     * Check if a model has been evaluated for a specific test
     */
    public function isModelEvaluated(Model $model, int $testNumber = 1): bool
    {
        $path = $this->getModelPath($model);
        $evaluationPath = $path . sprintf('/%02d_evaluation.json', $testNumber);
        return file_exists($evaluationPath);
    }

    /**
     * Check if a model has any evaluation
     */
    public function hasAnyEvaluation(Model $model): bool
    {
        $path = $this->getModelPath($model);
        if (!is_dir($path)) {
            return false;
        }

        $files = scandir($path);
        foreach ($files as $file) {
            if (preg_match('/^\d+_evaluation\.json$/', $file)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get models that have not been evaluated
     */
    public function getUnevaluatedModelIds(): array
    {
        $modelIds = [];
        $testedModels = $this->getTestedModelIds();

        foreach ($testedModels as $modelId) {
            $model = new Model($modelId, $modelId);
            if (!$this->hasAnyEvaluation($model)) {
                $modelIds[] = $modelId;
            }
        }

        return $modelIds;
    }

    /**
     * Get all tested model IDs
     */
    public function getTestedModelIds(): array
    {
        $modelIds = [];

        if (!is_dir($this->storagePath)) {
            return $modelIds;
        }

        $directories = scandir($this->storagePath);
        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $fullPath = $this->storagePath . '/' . $dir;
            if (is_dir($fullPath)) {
                // Check if this model has been tested (has any raw response file)
                $files = scandir($fullPath);
                $hasRawResponse = false;
                foreach ($files as $file) {
                    if (preg_match('/^\d+_raw_response\.json$/', $file)) {
                        $hasRawResponse = true;
                        break;
                    }
                }
                if ($hasRawResponse) {
                    $modelIds[] = $dir;
                }
            }
        }

        return $modelIds;
    }

    /**
     * Get the latest test result for a model
     */
    public function getLatestTestResult(Model $model): ?array
    {
        $path = $this->getModelPath($model);

        // Find the highest numbered raw response
        $maxTestNumber = 0;
        $latestResponse = null;

        for ($i = 1; $i <= 100; $i++) {
            $rawResponsePath = $path . sprintf('/%02d_raw_response.json', $i);
            if (file_exists($rawResponsePath)) {
                $maxTestNumber = $i;
                $content = file_get_contents($rawResponsePath);
                $latestResponse = json_decode($content, true);
            } else {
                break;
            }
        }

        return $latestResponse;
    }

    /**
     * Get the storage path
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    /**
     * Get the model path from a model ID string
     *
     * @param string $modelId The model ID
     * @return string The full path to the model's directory
     */
    public function getModelPathFromId(string $modelId): string
    {
        return ModelPathNormalizer::getModelPath($this->storagePath, $modelId);
    }
}
