<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LLMScoring\Client\OpenRouterClient;
use LLMScoring\Client\OpenRouterException;
use LLMScoring\Models\Model;
use LLMScoring\Models\ModelCollection;

describe('OpenRouterClient', function () {
    describe('hasApiKey', function () {
        it('returns true when valid API key is set', function () {
            $client = new OpenRouterClient('valid_api_key');
            expect($client->hasApiKey())->toBeTrue();
        });

        it('returns false when API key is placeholder', function () {
            $client = new OpenRouterClient('your_api_key_here');
            expect($client->hasApiKey())->toBeFalse();
        });

        it('returns false when API key is empty', function () {
            $client = new OpenRouterClient('');
            expect($client->hasApiKey())->toBeFalse();
        });
    });

    describe('getRateLimitDelay', function () {
        it('returns default rate limit delay', function () {
            $client = new OpenRouterClient('test_key');
            expect($client->getRateLimitDelay())->toBe(1);
        });

        it('returns custom rate limit delay', function () {
            $client = new OpenRouterClient('test_key', 1, 3);
            expect($client->getRateLimitDelay())->toBe(1);
        });
    });

    describe('setRateLimitDelay', function () {
        it('sets custom rate limit delay', function () {
            $client = new OpenRouterClient('test_key');
            $client->setRateLimitDelay(2);
            expect($client->getRateLimitDelay())->toBe(2);
        });
    });

    describe('fetchModels', function () {
        it('fetches and parses models from API response', function () {
            $mockResponse = new Response(200, [], json_encode([
                'data' => [
                    [
                        'id' => 'meta-llama/llama-3.1-8b-instruct',
                        'name' => 'Llama 3.1 8B Instruct',
                        'pricing' => [
                            'prompt' => '0.00005',
                            'completion' => '0.00006',
                        ],
                        'context_length' => 4096,
                        'provider' => 'Meta',
                    ],
                    [
                        'id' => 'anthropic/claude-3.5-sonnet',
                        'name' => 'Claude 3.5 Sonnet',
                        'pricing' => [
                            'prompt' => '0.003',
                            'completion' => '0.015',
                        ],
                        'context_length' => 200000,
                        'provider' => 'Anthropic',
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $models = $client->fetchModels();

            expect($models)->toBeInstanceOf(ModelCollection::class);
            expect($models->count())->toBe(2);

            $llama = $models->get('meta-llama/llama-3.1-8b-instruct');
            expect($llama)->not->toBeNull();
            expect($llama->getName())->toBe('Llama 3.1 8B Instruct');
            expect($llama->getPricingInput())->toBe('0.00005');
            expect($llama->getPricingOutput())->toBe('0.00006');
            expect($llama->getContextLength())->toBe(4096);
        });

        it('throws exception on invalid response format', function () {
            $mockResponse = new Response(200, [], json_encode([
                'invalid' => 'data',
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            expect(fn() => $client->fetchModels())
                ->toThrow(OpenRouterException::class, 'Invalid response format');
        });

        it('throws exception on API error', function () {
            $mockResponse = new Response(401, [], json_encode([
                'error' => [
                    'message' => 'Invalid API key',
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            expect(fn() => $client->fetchModels())
                ->toThrow(OpenRouterException::class);
        });
    });

    describe('sendChatCompletion', function () {
        it('sends chat completion request and returns response', function () {
            $mockResponse = new Response(200, [], json_encode([
                'id' => 'chatcmpl-123',
                'object' => 'chat.completion',
                'created' => 1677858242,
                'model' => 'meta-llama/llama-3.1-8b-instruct',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Hello! How can I help you?',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 15,
                    'total_tokens' => 25,
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $response = $client->sendChatCompletion(
                'meta-llama/llama-3.1-8b-instruct',
                [
                    ['role' => 'user', 'content' => 'Say hello!'],
                ]
            );

            expect($response['id'])->toBe('chatcmpl-123');
            expect($response['choices'][0]['message']['content'])->toBe('Hello! How can I help you?');
        });

        it('passes additional options to the API', function () {
            $mockResponse = new Response(200, [], json_encode([
                'id' => 'chatcmpl-123',
                'choices' => [
                    [
                        'message' => ['role' => 'assistant', 'content' => 'Response'],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);

            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $response = $client->sendChatCompletion(
                'test-model',
                [['role' => 'user', 'content' => 'Test']],
                [
                    'temperature' => 0.7,
                    'max_tokens' => 100,
                ]
            );

            expect($response['id'])->toBe('chatcmpl-123');
        });
    });
});
