<?php

declare(strict_types=1);

namespace LLMScoring\Models;

/**
 * Collection of Model instances
 */
class ModelCollection implements \IteratorAggregate, \Countable
{
    /** @var Model[] */
    private array $models = [];

    public function __construct(array $models = [])
    {
        foreach ($models as $model) {
            $this->add($model);
        }
    }

    public function add(Model $model): void
    {
        $this->models[$model->getId()] = $model;
    }

    public function get(string $id): ?Model
    {
        return $this->models[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->models[$id]);
    }

    public function remove(string $id): void
    {
        unset($this->models[$id]);
    }

    /**
     * Filter models by enabled status
     */
    public function filterEnabled(bool $enabled = true): self
    {
        $filtered = new self();
        foreach ($this->models as $id => $model) {
            if ($model->isEnabled() === $enabled) {
                $filtered->add($model);
            }
        }
        return $filtered;
    }

    /**
     * Filter models to only free models
     */
    public function filterFree(): self
    {
        $filtered = new self();
        foreach ($this->models as $id => $model) {
            if ($model->isFree()) {
                $filtered->add($model);
            }
        }
        return $filtered;
    }

    /**
     * Sort models by priority (descending)
     */
    public function sortByPriority(): self
    {
        $sorted = $this->models;
        uasort($sorted, fn(Model $a, Model $b) => $b->getPriority() - $a->getPriority());

        $collection = new self();
        $collection->models = $sorted;
        return $collection;
    }

    /**
     * Get all model IDs
     */
    public function getIds(): array
    {
        return array_keys($this->models);
    }

    /**
     * Convert to array
     *
     * @return Model[]
     */
    public function toArray(): array
    {
        return $this->models;
    }

    /**
     * Count all models
     */
    public function count(): int
    {
        return count($this->models);
    }

    /**
     * Get iterator
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->models);
    }
}
