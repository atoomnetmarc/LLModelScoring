<?php

declare(strict_types=1);

namespace LLMScoring\Runner;

use League\Csv\Reader;
use LLMScoring\Client\OpenRouterClientInterface;
use LLMScoring\Models\Model;
use LLMScoring\Models\ModelCollection;
use LLMScoring\Storage\StorageManager;

/**
 * TestRunner executes tests on models from CSV
 */
class TestRunner
{
    private OpenRouterClientInterface $client;
    private StorageManager $storageManager;
    private string $questionCode;

    public function __construct(OpenRouterClientInterface $client, ?StorageManager $storageManager = null, string $questionCode = 'default')
    {
        $this->client = $client;
        $this->questionCode = $questionCode;
        $this->storageManager = $storageManager ?? new StorageManager(null, $questionCode);
    }

    /**
     * Get the question code
     */
    public function getQuestionCode(): string
    {
        return $this->questionCode;
    }

    /**
     * Load models from CSV file with optional filtering
     */
    public function loadModelsFromCsv(
        string $csvPath,
        bool $enabledOnly = false,
        bool $freeOnly = false
    ): ModelCollection {
        if (!file_exists($csvPath)) {
            throw new \RuntimeException("CSV file not found: {$csvPath}");
        }

        $reader = Reader::from($csvPath);
        $reader->setHeaderOffset(0);

        $models = [];
        foreach ($reader->getRecords() as $record) {
            $models[] = Model::fromCsvRow($record);
        }

        $collection = new ModelCollection($models);

        // Apply filters
        if ($enabledOnly) {
            $collection = $collection->filterEnabled(true);
        }
        if ($freeOnly) {
            $collection = $collection->filterFree();
        }

        // Sort by priority
        $collection = $collection->sortByPriority();

        return $collection;
    }

    /**
     * Run a test on a specific model
     */
    public function testModel(Model $model, string $prompt): array
    {
        $response = $this->client->sendChatCompletion($model->getId(), [
            ['role' => 'user', 'content' => $prompt]
        ]);

        return [
            'model_id' => $model->getId(),
            'model_name' => $model->getName(),
            'prompt' => $prompt,
            'response' => $response,
            'timestamp' => date('c'),
        ];
    }

    /**
     * Test a model and save the results to storage
     */
    public function testModelAndSave(Model $model, string $prompt, int $testNumber = 1): array
    {
        // Save the prompt first
        $this->storageManager->saveTestPrompt($model, $prompt, $testNumber);

        // Send the prompt and get response
        $response = $this->client->sendChatCompletion($model->getId(), [
            ['role' => 'user', 'content' => $prompt]
        ]);

        // Save the raw response
        $this->storageManager->saveRawResponse($model, $response, $testNumber);

        return [
            'model_id' => $model->getId(),
            'model_name' => $model->getName(),
            'prompt' => $prompt,
            'response' => $response,
            'timestamp' => date('c'),
        ];
    }

    /**
     * Get the OpenRouter client
     */
    public function getClient(): OpenRouterClientInterface
    {
        return $this->client;
    }

    /**
     * Get the StorageManager
     */
    public function getStorageManager(): StorageManager
    {
        return $this->storageManager;
    }
}
