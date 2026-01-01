<?php

declare(strict_types=1);

namespace LLMScoring\Models;

/**
 * Represents a model from OpenRouter
 */
class Model
{
    private string $id;
    private string $name;
    private ?string $pricingInput;
    private ?string $pricingOutput;
    private ?int $contextLength;
    private bool $enabled;
    private int $priority;
    private ?string $provider;

    public function __construct(
        string $id,
        string $name,
        ?string $pricingInput = null,
        ?string $pricingOutput = null,
        ?int $contextLength = null,
        bool $enabled = true,
        int $priority = 0,
        ?string $provider = null
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->pricingInput = $pricingInput;
        $this->pricingOutput = $pricingOutput;
        $this->contextLength = $contextLength;
        $this->enabled = $enabled;
        $this->priority = $priority;
        $this->provider = $provider;
    }

    /**
     * Create a Model from OpenRouter API data
     */
    public static function fromOpenRouterData(array $data): self
    {
        $id = $data['id'] ?? '';
        $name = $data['name'] ?? $id;

        // Extract pricing from architecture
        $pricingInput = null;
        $pricingOutput = null;
        $contextLength = null;
        $provider = null;

        if (isset($data['architecture'])) {
            $pricingInput = $data['architecture']['input_modalities'] ?? null;
            $pricingOutput = $data['architecture']['output_modalities'] ?? null;
        }

        if (isset($data['pricing'])) {
            $pricingInput = $data['pricing']['prompt'] ?? $pricingInput;
            $pricingOutput = $data['pricing']['completion'] ?? $pricingOutput;
        }

        if (isset($data['context_length'])) {
            $contextLength = (int) $data['context_length'];
        }

        if (isset($data['provider'])) {
            $provider = $data['provider'] ?? null;
        }

        return new self(
            $id,
            $name,
            $pricingInput,
            $pricingOutput,
            $contextLength,
            true, // All models from API are enabled by default
            0,
            $provider
        );
    }

    /**
     * Create a Model from CSV row data
     */
    public static function fromCsvRow(array $row): self
    {
        return new self(
            $row['model_id'] ?? '',
            $row['name'] ?? '',
            $row['pricing_input'] ?? null,
            $row['pricing_output'] ?? null,
            isset($row['context_length']) ? (int) $row['context_length'] : null,
            ($row['enabled'] ?? '1') === '1',
            (int) ($row['priority'] ?? 0),
            $row['provider'] ?? null
        );
    }

    /**
     * Convert to CSV row format
     */
    public function toCsvRow(): array
    {
        return [
            'model_id' => $this->id,
            'name' => $this->name,
            'pricing_input' => $this->pricingInput ?? '',
            'pricing_output' => $this->pricingOutput ?? '',
            'context_length' => $this->contextLength ?? '',
            'enabled' => $this->enabled ? '1' : '0',
            'priority' => $this->priority,
            'provider' => $this->provider ?? '',
        ];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPricingInput(): ?string
    {
        return $this->pricingInput;
    }

    public function getPricingOutput(): ?string
    {
        return $this->pricingOutput;
    }

    public function getContextLength(): ?int
    {
        return $this->contextLength;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    /**
     * Check if this is a free model (no input or output cost)
     */
    public function isFree(): bool
    {
        $inputPrice = $this->pricingInput ?? '0';
        $outputPrice = $this->pricingOutput ?? '0';

        return ($inputPrice === '0' || $inputPrice === '0.0' || $inputPrice === '0.00')
            && ($outputPrice === '0' || $outputPrice === '0.0' || $outputPrice === '0.00');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'pricing_input' => $this->pricingInput,
            'pricing_output' => $this->pricingOutput,
            'context_length' => $this->contextLength,
            'enabled' => $this->enabled,
            'priority' => $this->priority,
            'provider' => $this->provider,
        ];
    }
}
