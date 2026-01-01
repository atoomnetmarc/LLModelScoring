<?php

declare(strict_types=1);

namespace LLMScoring\Client;

use LLMScoring\Models\ModelCollection;

/**
 * Interface for OpenRouter API client
 */
interface OpenRouterClientInterface
{
    /**
     * Check if API key is configured
     */
    public function hasApiKey(): bool;

    /**
     * Fetch all available models from OpenRouter
     */
    public function fetchModels(): ModelCollection;

    /**
     * Send a chat completion request
     *
     * @param string $modelId The model ID to use
     * @param array $messages Array of messages with 'role' and 'content'
     * @param array $options Additional options (temperature, max_tokens, etc.)
     * @return array The response data
     */
    public function sendChatCompletion(string $modelId, array $messages, array $options = []): array;
}
