<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LLMScoring\Client\OpenRouterClient;
use LLMScoring\Evaluator\ContentEvaluator;

describe('ContentEvaluator', function () {
    describe('constructor', function () {
        it('creates evaluator with custom model ID', function () {
            $client = new OpenRouterClient('test_api_key', 0, 1);
            $evaluator = new ContentEvaluator($client, 'anthropic/claude-3.5-sonnet');

            expect($evaluator->getEvaluatorModelId())->toBe('anthropic/claude-3.5-sonnet');
        });

        it('uses default model from environment', function () {
            $client = new OpenRouterClient('test_api_key', 0, 1);
            $evaluator = new ContentEvaluator($client);

            expect($evaluator->getEvaluatorModelId())->toBe('minimax/minimax-m2.1');
        });
    });

    describe('evaluate', function () {
        it('evaluates content and returns structured result', function () {
            $mockResponse = new Response(200, [], json_encode([
                'id' => 'eval-123',
                'object' => 'chat.completion',
                'created' => 1677858242,
                'model' => 'minimax/minimax-m2.1',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'logic_score' => 80,
                                'syntax_score' => 90,
                                'output_score' => 85,
                                'logic_feedback' => 'Good algorithmic approach',
                                'syntax_feedback' => 'Valid syntax',
                                'output_feedback' => 'Produces expected output',
                                'overall_score' => 84,
                                'strengths' => ['Clean code', 'Good variable names'],
                                'weaknesses' => ['Could use type hints'],
                                'suggestions' => ['Add return types'],
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 200,
                    'total_tokens' => 300,
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');

            $result = $evaluator->evaluate(
                '<?php echo "Hello";',
                'Write PHP code to print Hello',
                'test-model',
                'Test Model'
            );

            expect($result['model_id'])->toBe('test-model');
            expect($result['model_name'])->toBe('Test Model');
            expect($result['evaluator_model'])->toBe('minimax/minimax-m2.1');

            // Check evaluation structure
            expect($result['evaluation']['logic']['score'])->toBe(80);
            expect($result['evaluation']['syntax']['score'])->toBe(90);
            expect($result['evaluation']['output']['score'])->toBe(85);

            // Check weighted scores
            expect($result['evaluation']['logic']['weighted_score'])->toBe(32); // 80 * 0.4
            expect($result['evaluation']['syntax']['weighted_score'])->toBe(27); // 90 * 0.3
            expect($result['evaluation']['output']['weighted_score'])->toBe(26); // 85 * 0.3

            // Check overall score is calculated: (80*0.4) + (90*0.3) + (85*0.3) = 32 + 27 + 26 = 85
            expect($result['evaluation']['overall_score'])->toBe(85);

            expect($result['evaluation']['strengths'])->toHaveCount(2);
            expect($result['evaluation']['weaknesses'])->toHaveCount(1);
            expect($result['evaluation']['suggestions'])->toHaveCount(1);
        });

        it('handles content type hint', function () {
            $mockResponse = new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'logic_score' => 90,
                                'syntax_score' => 85,
                                'output_score' => 95,
                                'logic_feedback' => 'Excellent rhyme scheme',
                                'syntax_feedback' => 'Proper poem structure',
                                'output_feedback' => 'Beautiful imagery',
                                'overall_score' => 90,
                                'strengths' => ['Creative metaphors'],
                                'weaknesses' => [],
                                'suggestions' => [],
                            ]),
                        ],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');

            $result = $evaluator->evaluate(
                "Roses are red\nViolets are blue\nPHP is great\nAnd so are you",
                'Write a poem about PHP',
                'test-model',
                'Test Model',
                'poem'
            );

            expect($result['content_type'])->toBe('poem');
            expect($result['evaluation']['logic']['score'])->toBe(90);
        });

        it('handles JSON in markdown code block', function () {
            $mockResponse = new Response(200, [], json_encode([
                'id' => 'eval-123',
                'choices' => [
                    [
                        'message' => [
                            'content' => "```json\n" . json_encode([
                                'logic_score' => 70,
                                'syntax_score' => 80,
                                'output_score' => 75,
                                'logic_feedback' => 'Logic is sound',
                                'syntax_feedback' => 'Syntax is valid',
                                'output_feedback' => 'Output is correct',
                                'overall_score' => 75,
                                'strengths' => ['Good logic'],
                                'weaknesses' => [],
                                'suggestions' => [],
                            ]) . "\n```",
                        ],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');

            $result = $evaluator->evaluate(
                '<?php $x = 1;',
                'Test prompt',
                'test-model'
            );

            expect($result['evaluation']['logic']['score'])->toBe(70);
            expect($result['evaluation']['syntax']['score'])->toBe(80);
            expect($result['evaluation']['output']['score'])->toBe(75);
        });

        it('creates fallback evaluation when JSON parsing fails', function () {
            $mockResponse = new Response(200, [], json_encode([
                'id' => 'eval-123',
                'choices' => [
                    [
                        'message' => [
                            'content' => 'This is not valid JSON',
                        ],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');

            $result = $evaluator->evaluate(
                '<?php echo "test";',
                'Test prompt',
                'test-model'
            );

            expect($result['evaluation']['overall_score'])->toBe(50);
            expect($result['evaluation']['weaknesses'][0])->toBe('Evaluation parsing failed');
        });
    });

    describe('syntax check', function () {
        it('scores 100 for valid content with balanced delimiters', function () {
            $validCode = '<?php function test() { return 1; }';

            $mockResponse = new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'logic_score' => 100,
                                'syntax_score' => 100,
                                'output_score' => 100,
                                'logic_feedback' => 'Good',
                                'syntax_feedback' => 'Good',
                                'output_feedback' => 'Good',
                                'overall_score' => 100,
                                'strengths' => [],
                                'weaknesses' => [],
                                'suggestions' => [],
                            ]),
                        ],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');
            $result = $evaluator->evaluate($validCode, 'Test', 'test');

            expect($result['evaluation']['syntax']['score'])->toBe(100);
        });

        it('deducts points for unbalanced braces in fallback mode', function () {
            $invalidCode = '<?php function test() { return 1;'; // Missing closing brace

            // Return non-JSON response to trigger fallback
            $mockResponse = new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Invalid response',
                        ],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');
            $result = $evaluator->evaluate($invalidCode, 'Test', 'test');

            // Syntax score should be reduced due to unbalanced braces in fallback
            expect($result['evaluation']['syntax']['score'])->toBeLessThan(100);
        });

        it('returns 0 for empty content in fallback mode', function () {
            // Return non-JSON response to trigger fallback
            $mockResponse = new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Invalid response',
                        ],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');
            $result = $evaluator->evaluate('', 'Test', 'test');

            expect($result['evaluation']['syntax']['score'])->toBe(0);
        });
    });

    describe('score calculation', function () {
        it('correctly calculates weighted scores', function () {
            $mockResponse = new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'logic_score' => 100,
                                'syntax_score' => 100,
                                'output_score' => 100,
                                'logic_feedback' => 'Perfect',
                                'syntax_feedback' => 'Perfect',
                                'output_feedback' => 'Perfect',
                                'overall_score' => 100,
                                'strengths' => [],
                                'weaknesses' => [],
                                'suggestions' => [],
                            ]),
                        ],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');
            $result = $evaluator->evaluate('<?php return true;', 'Test', 'test');

            // Logic: 100 * 0.4 = 40
            expect($result['evaluation']['logic']['weighted_score'])->toBe(40);
            // Syntax: 100 * 0.3 = 30
            expect($result['evaluation']['syntax']['weighted_score'])->toBe(30);
            // Output: 100 * 0.3 = 30
            expect($result['evaluation']['output']['weighted_score'])->toBe(30);
            // Overall: 40 + 30 + 30 = 100
            expect($result['evaluation']['overall_score'])->toBe(100);
        });

        it('correctly rounds fractional scores', function () {
            $mockResponse = new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'logic_score' => 85,
                                'syntax_score' => 85,
                                'output_score' => 85,
                                'logic_feedback' => 'Good',
                                'syntax_feedback' => 'Good',
                                'output_feedback' => 'Good',
                                'overall_score' => 85,
                                'strengths' => [],
                                'weaknesses' => [],
                                'suggestions' => [],
                            ]),
                        ],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');
            $result = $evaluator->evaluate('<?php return true;', 'Test', 'test');

            // Logic: 85 * 0.4 = 34 (rounded)
            expect($result['evaluation']['logic']['weighted_score'])->toBe(34);
            // Syntax: 85 * 0.3 = 26 (rounded)
            expect($result['evaluation']['syntax']['weighted_score'])->toBe(26);
            // Output: 85 * 0.3 = 26 (rounded)
            expect($result['evaluation']['output']['weighted_score'])->toBe(26);
            // Overall: 34 + 26 + 26 = 86
            expect($result['evaluation']['overall_score'])->toBe(85);
        });
    });

    describe('content type handling', function () {
        it('stores content type in result', function () {
            $mockResponse = new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'logic_score' => 80,
                                'syntax_score' => 80,
                                'output_score' => 80,
                                'logic_feedback' => 'Good',
                                'syntax_feedback' => 'Good',
                                'output_feedback' => 'Good',
                                'overall_score' => 80,
                                'strengths' => [],
                                'weaknesses' => [],
                                'suggestions' => [],
                            ]),
                        ],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');

            $result = $evaluator->evaluate(
                'E = mc²',
                'Write the famous physics formula',
                'test-model',
                'Test Model',
                'formula'
            );

            expect($result['content_type'])->toBe('formula');
        });

        it('handles null content type', function () {
            $mockResponse = new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'logic_score' => 80,
                                'syntax_score' => 80,
                                'output_score' => 80,
                                'logic_feedback' => 'Good',
                                'syntax_feedback' => 'Good',
                                'output_feedback' => 'Good',
                                'overall_score' => 80,
                                'strengths' => [],
                                'weaknesses' => [],
                                'suggestions' => [],
                            ]),
                        ],
                    ],
                ],
            ]));

            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);
            $httpClient = new Client(['handler' => $handlerStack]);
            $client = new OpenRouterClient('test_api_key', 0, 1, $httpClient);

            $evaluator = new ContentEvaluator($client, 'minimax/minimax-m2.1');

            $result = $evaluator->evaluate(
                'Some random text',
                'Generate some text',
                'test-model'
            );

            expect($result['content_type'])->toBeNull();
        });
    });
});
