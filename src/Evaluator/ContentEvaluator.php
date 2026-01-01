<?php

declare(strict_types=1);

namespace LLMScoring\Evaluator;

use LLMScoring\Client\OpenRouterClientInterface;

/**
 * ContentEvaluator evaluates generated content using an LLM model
 *
 * Scoring breakdown:
 * - Logic: 40%
 * - Syntax/Correctness: 30%
 * - Output Quality: 30%
 *
 * This evaluator is generic and can evaluate any type of content:
 * - Code (PHP, Python, JavaScript, etc.)
 * - Text responses
 * - Formulas
 * - Poems
 * - And more
 *
 * Evaluation hints can be loaded from data/evaluator-hints.md to provide
 * task-specific guidance for the evaluator.
 */
class ContentEvaluator
{
    private OpenRouterClientInterface $client;
    private string $evaluatorModelId;
    private ?string $evaluatorHints = null;

    public function __construct(
        OpenRouterClientInterface $client,
        ?string $evaluatorModelId = null,
        ?string $evaluatorHintsPath = null
    ) {
        $this->client = $client;
        $this->evaluatorModelId = $evaluatorModelId ?? ($_ENV['EVALUATOR_MODEL'] ?? getenv('EVALUATOR_MODEL') ?: 'minimax/minimax-m2.1');
        $this->evaluatorHints = $this->loadEvaluatorHints($evaluatorHintsPath);
    }

    /**
     * Load evaluator hints from file
     */
    private function loadEvaluatorHints(?string $hintsPath): ?string
    {
        $path = $hintsPath ?? 'data/evaluator-hints.md';
        if (!file_exists($path) || filesize($path) === 0) {
            return null;
        }
        return file_get_contents($path);
    }

    /**
     * Evaluate generated content
     *
     * @param string $content The content to evaluate
     * @param string $prompt The original prompt that generated the content
     * @param string $modelId The ID of the model that generated the content
     * @param string $modelName The name of the model that generated the content
     * @param string $contentType Optional hint about the content type (e.g., "PHP code", "poem", "formula")
     * @return array The evaluation result
     */
    public function evaluate(
        string $content,
        string $prompt,
        string $modelId,
        string $modelName = 'Unknown',
        ?string $contentType = null
    ): array {
        $evaluationPrompt = $this->buildEvaluationPrompt($content, $prompt, $contentType);

        $response = $this->client->sendChatCompletion(
            $this->evaluatorModelId,
            [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
                ['role' => 'user', 'content' => $evaluationPrompt],
            ]
        );

        return $this->parseEvaluationResponse($response, $modelId, $modelName, $content, $prompt, $contentType);
    }

    /**
     * Build the evaluation prompt
     */
    private function buildEvaluationPrompt(string $content, string $prompt, ?string $contentType): string
    {
        $typeHint = $contentType ? "The content should be: {$contentType}.\n\n" : '';

        // Add evaluator hints if available
        $hintsSection = '';
        if ($this->evaluatorHints) {
            $hintsSection = <<<HINTS

**Evaluator Hints (Task-Specific Guidance):**
{$this->evaluatorHints}

HINTS;
        }

        return <<<PROMPT
Please evaluate the following content that was generated for this prompt:

**Original Prompt:**
{$prompt}

{$typeHint}**Generated Content:**
{$content}
{$hintsSection}
Evaluate the content based on three criteria:

1. **Logic (40%)**: Is the content logically correct and does it answer the prompt appropriately? Is the reasoning sound?
2. **Syntax/Correctness (30%)**: Is the content well-formed and structurally correct? (For code: valid syntax; For text: proper grammar and structure)
3. **Output Quality (30%)**: Is the content high quality, clear, and well-presented?

For each criterion, provide a score from 0-100 and brief feedback.

Respond in JSON format only:
```json
{
  "logic_score": <0-100>,
  "syntax_score": <0-100>,
  "output_score": <0-100>,
  "logic_feedback": "<brief explanation>",
  "syntax_feedback": "<brief explanation>",
  "output_feedback": "<brief explanation>",
  "overall_score": <0-100>,
  "strengths": ["<strength 1>", "<strength 2>"],
  "weaknesses": ["<weakness 1>", "<weakness 2>"],
  "suggestions": ["<suggestion 1>", "<suggestion 2>"]
}
```

Provide only the JSON, no additional text.
PROMPT;
    }

    /**
     * Get the system prompt for the evaluator
     */
    private function getSystemPrompt(): string
    {
        return <<<'SYSTEM_PROMPT'
You are an expert content evaluator. You analyze generated content and provide fair, objective assessments of quality. Your evaluations should be constructive and focused on helping improve content quality.

You can evaluate any type of content:
- Code in any programming language
- Text responses and explanations
- Creative writing (poems, stories)
- Mathematical formulas
- Structured data
- And more

Always respond in valid JSON format. Be critical but fair in your assessments.
SYSTEM_PROMPT;
    }

    /**
     * Parse the evaluation response from the LLM
     */
    private function parseEvaluationResponse(
        array $response,
        string $modelId,
        string $modelName,
        string $content,
        string $prompt,
        ?string $contentType
    ): array {
        $rawContent = $this->extractContent($response);

        // Try to parse JSON from the response
        $json = $this->extractJson($rawContent);

        if ($json !== null) {
            return $this->formatEvaluationResult($json, $modelId, $modelName, $content, $prompt, $contentType);
        }

        // Fallback: create a basic evaluation if JSON parsing fails
        return $this->createFallbackEvaluation($modelId, $modelName, $content, $prompt, $contentType, $rawContent);
    }

    /**
     * Extract content from the response
     */
    private function extractContent(array $response): string
    {
        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }

        if (isset($response['choices'][0]['content'])) {
            return $response['choices'][0]['content'];
        }

        return json_encode($response);
    }

    /**
     * Extract JSON from response content
     */
    private function extractJson(string $content): ?array
    {
        // Try direct JSON decode first
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Try to find JSON in markdown code blocks
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $content, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Try to find JSON object anywhere in the text
        if (preg_match('/\{[^{}]*"logic_score"[^{}]*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Format the evaluation result
     */
    private function formatEvaluationResult(
        array $evaluation,
        string $modelId,
        string $modelName,
        string $content,
        string $prompt,
        ?string $contentType
    ): array {
        $logicScore = (int) ($evaluation['logic_score'] ?? 0);
        $syntaxScore = (int) ($evaluation['syntax_score'] ?? 0);
        $outputScore = (int) ($evaluation['output_score'] ?? 0);

        // Calculate weighted overall score
        $overallScore = (int) round(
            ($logicScore * 0.4) + ($syntaxScore * 0.3) + ($outputScore * 0.3)
        );

        return [
            'model_id' => $modelId,
            'model_name' => $modelName,
            'evaluator_model' => $this->evaluatorModelId,
            'content_type' => $contentType,
            'timestamp' => date('c'),
            'prompt' => $prompt,
            'content' => $content,
            'evaluation' => [
                'logic' => [
                    'score' => $logicScore,
                    'weight' => 0.4,
                    'weighted_score' => (int) round($logicScore * 0.4),
                    'feedback' => $evaluation['logic_feedback'] ?? 'No feedback provided',
                ],
                'syntax' => [
                    'score' => $syntaxScore,
                    'weight' => 0.3,
                    'weighted_score' => (int) round($syntaxScore * 0.3),
                    'feedback' => $evaluation['syntax_feedback'] ?? 'No feedback provided',
                ],
                'output' => [
                    'score' => $outputScore,
                    'weight' => 0.3,
                    'weighted_score' => (int) round($outputScore * 0.3),
                    'feedback' => $evaluation['output_feedback'] ?? 'No feedback provided',
                ],
                'overall_score' => $overallScore,
                'strengths' => $evaluation['strengths'] ?? [],
                'weaknesses' => $evaluation['weaknesses'] ?? [],
                'suggestions' => $evaluation['suggestions'] ?? [],
            ],
        ];
    }

    /**
     * Create a fallback evaluation when JSON parsing fails
     */
    private function createFallbackEvaluation(
        string $modelId,
        string $modelName,
        string $content,
        string $prompt,
        ?string $contentType,
        string $rawResponse
    ): array {
        // Basic content check
        $syntaxScore = $this->performBasicContentCheck($content);

        return [
            'model_id' => $modelId,
            'model_name' => $modelName,
            'evaluator_model' => $this->evaluatorModelId,
            'content_type' => $contentType,
            'timestamp' => date('c'),
            'prompt' => $prompt,
            'content' => $content,
            'evaluation' => [
                'logic' => [
                    'score' => 50,
                    'weight' => 0.4,
                    'weighted_score' => 20,
                    'feedback' => 'Could not parse LLM response for logic evaluation',
                ],
                'syntax' => [
                    'score' => $syntaxScore,
                    'weight' => 0.3,
                    'weighted_score' => (int) round($syntaxScore * 0.3),
                    'feedback' => $contentType
                        ? "Basic check for {$contentType}: " . ($syntaxScore >= 80 ? 'appears valid' : 'potential issues detected')
                        : ($syntaxScore >= 80 ? 'Content appears well-formed' : 'Potential structural issues detected'),
                ],
                'output' => [
                    'score' => 50,
                    'weight' => 0.3,
                    'weighted_score' => 15,
                    'feedback' => 'Could not parse LLM response for output evaluation',
                ],
                'overall_score' => 50,
                'strengths' => [],
                'weaknesses' => ['Evaluation parsing failed'],
                'suggestions' => ['Review the raw response for manual evaluation'],
            ],
            'raw_response' => $rawResponse,
        ];
    }

    /**
     * Perform a basic content structure check
     */
    private function performBasicContentCheck(string $content): int
    {
        if (empty(trim($content))) {
            return 0;
        }

        // Check for balanced delimiters based on content type
        $score = 100;
        $issues = [];

        // Check for balanced braces (common in code)
        $openBraces = substr_count($content, '{');
        $closeBraces = substr_count($content, '}');
        if ($openBraces !== $closeBraces) {
            $score -= 20;
            $issues[] = 'Unbalanced braces';
        }

        // Check for balanced parentheses
        $openParens = substr_count($content, '(');
        $closeParens = substr_count($content, ')');
        if ($openParens !== $closeParens) {
            $score -= 20;
            $issues[] = 'Unbalanced parentheses';
        }

        // Check for balanced brackets
        $openBrackets = substr_count($content, '[');
        $closeBrackets = substr_count($content, ']');
        if ($openBrackets !== $closeBrackets) {
            $score -= 15;
            $issues[] = 'Unbalanced brackets';
        }

        // Check for balanced quotes (basic check)
        $singleQuotes = substr_count($content, "'");
        $doubleQuotes = substr_count($content, '"');
        if (($singleQuotes % 2) !== 0 || ($doubleQuotes % 2) !== 0) {
            $score -= 10;
            $issues[] = 'Potential unbalanced quotes';
        }

        // Check for very short content
        if (strlen(trim($content)) < 10) {
            $score -= 10;
            $issues[] = 'Content is very short';
        }

        return max(0, $score);
    }

    /**
     * Get the evaluator model ID
     */
    public function getEvaluatorModelId(): string
    {
        return $this->evaluatorModelId;
    }
}
