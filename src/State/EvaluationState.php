<?php

declare(strict_types=1);

namespace LLMScoring\State;

/**
 * Represents the evaluation state for a single model
 */
class EvaluationState
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    private string $modelId;
    private string $modelName;
    private string $status;
    private ?string $startedAt;
    private ?string $completedAt;
    private ?string $errorMessage;
    private array $metadata;

    public function __construct(
        string $modelId,
        string $modelName,
        string $status = self::STATUS_PENDING,
        ?string $startedAt = null,
        ?string $completedAt = null,
        ?string $errorMessage = null,
        array $metadata = []
    ) {
        $this->modelId = $modelId;
        $this->modelName = $modelName;
        $this->status = $status;
        $this->startedAt = $startedAt;
        $this->completedAt = $completedAt;
        $this->errorMessage = $errorMessage;
        $this->metadata = $metadata;
    }

    /**
     * Create from array data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['model_id'] ?? '',
            $data['model_name'] ?? '',
            $data['status'] ?? self::STATUS_PENDING,
            $data['started_at'] ?? null,
            $data['completed_at'] ?? null,
            $data['error_message'] ?? null,
            $data['metadata'] ?? []
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'model_id' => $this->modelId,
            'model_name' => $this->modelName,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'error_message' => $this->errorMessage,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Mark as in progress
     */
    public function start(): void
    {
        $this->status = self::STATUS_IN_PROGRESS;
        $this->startedAt = date('c');
    }

    /**
     * Mark as completed
     */
    public function complete(array $metadata = []): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = date('c');
        $this->metadata = array_merge($this->metadata, $metadata);
    }

    /**
     * Mark as failed
     */
    public function fail(string $errorMessage): void
    {
        $this->status = self::STATUS_FAILED;
        $this->completedAt = date('c');
        $this->errorMessage = $errorMessage;
    }

    public function getModelId(): string
    {
        return $this->modelId;
    }

    public function getModelName(): string
    {
        return $this->modelName;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStartedAt(): ?string
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?string
    {
        return $this->completedAt;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isFinished(): bool
    {
        return $this->isCompleted() || $this->isFailed();
    }
}
