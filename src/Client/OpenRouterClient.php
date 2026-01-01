<?php

declare(strict_types=1);

namespace LLMScoring\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use LLMScoring\Models\Model;
use LLMScoring\Models\ModelCollection;

/**
 * Client for communicating with the OpenRouter API
 */
class OpenRouterClient implements OpenRouterClientInterface
{
    private Client $client;
    private string $apiKey;
    private int $rateLimitDelay;
    private int $maxRetries;

    private ?float $lastRequestTime = null;

    public function __construct(
        ?string $apiKey = null,
        ?int $rateLimitDelay = null,
        ?int $maxRetries = null,
        ?Client $httpClient = null
    ) {
        // Try multiple methods to get env variables for CLI compatibility
        $this->apiKey = $apiKey ?? ($_ENV['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: '');
        $this->rateLimitDelay = $rateLimitDelay ?? (int) ($_ENV['RATE_LIMIT_DELAY'] ?? getenv('RATE_LIMIT_DELAY') ?: 1);
        $this->maxRetries = $maxRetries ?? (int) ($_ENV['MAX_RETRIES'] ?? getenv('MAX_RETRIES') ?: 3);

        if ($httpClient !== null) {
            $this->client = $httpClient;
        } else {
            $this->client = new Client([
                'base_uri' => 'https://openrouter.ai/api/v1/',
                'timeout' => 300.0, // 5 minutes for slow models
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'HTTP-Referer' => 'https://github.com/atoomnetmarc/LLModelScoring',
                    'X-Title' => 'LLModelScoring',
                ],
                // Use curl handler with proper SSL/TLS config
                'curl' => [
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ],
            ]);
        }
    }

    /**
     * Check if API key is configured
     */
    public function hasApiKey(): bool
    {
        return !empty($this->apiKey) && $this->apiKey !== 'your_api_key_here';
    }

    /**
     * Fetch all available models from OpenRouter
     *
     * @return ModelCollection Collection of available models
     * @throws OpenRouterException
     */
    public function fetchModels(): ModelCollection
    {
        $this->enforceRateLimit();

        $response = $this->requestWithRetry('GET', 'models');

        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data['data'])) {
            throw new OpenRouterException('Invalid response format from OpenRouter API');
        }

        $models = [];
        foreach ($data['data'] as $modelData) {
            $models[] = Model::fromOpenRouterData($modelData);
        }

        return new ModelCollection($models);
    }

    /**
     * Send a chat completion request
     *
     * @param string $modelId The model ID to use
     * @param array $messages Array of messages with 'role' and 'content'
     * @param array $options Additional options (temperature, max_tokens, etc.)
     * @return array The response data
     * @throws OpenRouterException
     */
    public function sendChatCompletion(string $modelId, array $messages, array $options = []): array
    {
        $this->enforceRateLimit();

        $body = array_merge([
            'model' => $modelId,
            'messages' => $messages,
        ], $options);

        $response = $this->requestWithRetry('POST', 'chat/completions', [
            'json' => $body,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Make a request with automatic retry on rate limit errors
     *
     * @param string $method HTTP method
     * @param string $uri URI
     * @param array $options Request options
     * @return \Psr\Http\Message\ResponseInterface
     * @throws OpenRouterException
     */
    private function requestWithRetry(string $method, string $uri, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $response = $this->client->request($method, $uri, $options);

                // Check for rate limit status
                if ($response->getStatusCode() === 429) {
                    $attempt++;
                    $retryAfterSeconds = $this->getRetryAfterSeconds($response);

                    // If Retry-After is more than 30 seconds, fail immediately
                    if ($retryAfterSeconds > 30) {
                        throw new OpenRouterException(
                            "Rate limit: Retry-After value ({$retryAfterSeconds}s) exceeds maximum allowed (30s)"
                        );
                    }

                    if ($attempt >= $this->maxRetries) {
                        throw new OpenRouterException(
                            "Rate limit exceeded after {$this->maxRetries} attempts. Retry after: {$retryAfterSeconds}s"
                        );
                    }

                    usleep((int) ($retryAfterSeconds * 1000000));
                    continue;
                }

                return $response;
            } catch (GuzzleException $e) {
                $lastException = $e;

                // Check if it's a rate limit error
                if ($e instanceof \GuzzleHttp\Exception\ClientException) {
                    $response = $e->getResponse();
                    if ($response->getStatusCode() === 429) {
                        $attempt++;
                        $retryAfterSeconds = $this->getRetryAfterSeconds($response);

                        // If Retry-After is more than 30 seconds, fail immediately
                        if ($retryAfterSeconds > 30) {
                            throw new OpenRouterException(
                                "Rate limit: Retry-After value ({$retryAfterSeconds}s) exceeds maximum allowed (30s)"
                            );
                        }

                        if ($attempt >= $this->maxRetries) {
                            throw new OpenRouterException(
                                "Rate limit exceeded after {$this->maxRetries} attempts. Retry after: {$retryAfterSeconds}s"
                            );
                        }

                        usleep((int) ($retryAfterSeconds * 1000000));
                        continue;
                    }
                }

                throw new OpenRouterException(
                    "API request failed: {$e->getMessage()}",
                    $e->getCode(),
                    $e
                );
            }
        }

        throw new OpenRouterException(
            "Request failed after {$this->maxRetries} attempts: {$lastException->getMessage()}"
        );
    }

    /**
     * Get retry-after value from response headers (always in seconds)
     */
    private function getRetryAfterSeconds(\Psr\Http\Message\ResponseInterface $response): int
    {
        $headers = $response->getHeader('Retry-After');
        if (!empty($headers)) {
            $value = trim($headers[0]);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        // Default to rate limit delay (already in seconds)
        return $this->rateLimitDelay;
    }

    /**
     * Enforce rate limiting between requests
     */
    private function enforceRateLimit(): void
    {
        if ($this->lastRequestTime !== null) {
            $elapsed = (microtime(true) - $this->lastRequestTime) * 1000;
            $delay = max(0, $this->rateLimitDelay - $elapsed);

            if ($delay > 0) {
                usleep((int) ($delay * 1000));
            }
        }

        $this->lastRequestTime = microtime(true);
    }

    /**
     * Get the rate limit delay in seconds
     */
    public function getRateLimitDelay(): int
    {
        return $this->rateLimitDelay;
    }

    /**
     * Set the rate limit delay in seconds
     */
    public function setRateLimitDelay(int $seconds): void
    {
        $this->rateLimitDelay = $seconds;
    }
}
