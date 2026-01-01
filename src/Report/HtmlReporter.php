<?php

declare(strict_types=1);

namespace LLMScoring\Report;

use LLMScoring\Storage\StorageManager;

/**
 * HtmlReporter generates HTML reports from evaluation data
 */
class HtmlReporter
{
    private StorageManager $storageManager;

    public function __construct(?StorageManager $storageManager = null, string $questionCode = 'default')
    {
        $this->storageManager = $storageManager ?? new StorageManager(null, $questionCode);
    }

    /**
     * Generate an HTML report and save to file
     */
    public function generateReport(string $outputPath): array
    {
        $testedModelIds = $this->storageManager->getTestedModelIds();

        $reportData = [
            'generated_at' => date('c'),
            'total_models' => count($testedModelIds),
            'models' => [],
            'statistics' => $this->calculateStatistics($testedModelIds),
        ];

        foreach ($testedModelIds as $modelId) {
            $modelData = $this->gatherModelEvaluationData($modelId);
            if ($modelData !== null) {
                $reportData['models'][] = $modelData;
            }
        }

        // Sort by overall score (highest first)
        usort($reportData['models'], fn($a, $b) => $b['overall_score'] <=> $a['overall_score']);

        // Generate HTML
        $html = $this->renderHtml($reportData);

        // Save to file
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($outputPath, $html);

        return $reportData;
    }

    /**
     * Get the report data without generating HTML
     */
    public function getReportData(): array
    {
        $testedModelIds = $this->storageManager->getTestedModelIds();

        $reportData = [
            'generated_at' => date('c'),
            'total_models' => count($testedModelIds),
            'models' => [],
            'statistics' => $this->calculateStatistics($testedModelIds),
        ];

        foreach ($testedModelIds as $modelId) {
            $modelData = $this->gatherModelEvaluationData($modelId);
            if ($modelData !== null) {
                $reportData['models'][] = $modelData;
            }
        }

        // Sort by overall score (highest first)
        usort($reportData['models'], fn($a, $b) => $b['overall_score'] <=> $a['overall_score']);

        return $reportData;
    }

    /**
     * Render HTML from report data
     */
    private function renderHtml(array $reportData): string
    {
        $stats = $reportData['statistics'];
        $models = $reportData['models'];

        $generatedAt = $reportData['generated_at'];
        $totalModels = $stats['total_models'];
        $evaluatedCount = $stats['evaluated_count'];
        $averageScore = number_format($stats['average_score'], 1);
        $highestScore = number_format($stats['highest_score'], 1);
        $totalTokens = number_format($stats['total_tokens']);
        $totalCost = number_format($stats['total_cost'], 4);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LLM Model Evaluation Report</title>
    <style>
        :root {
            --primary-color: #2563eb;
            --success-color: #16a34a;
            --warning-color: #ca8a04;
            --danger-color: #dc2626;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --text-color: #1e293b;
            --border-color: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            margin-bottom: 3rem;
            padding-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
        }

        h1 {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .subtitle {
            color: #64748b;
            font-size: 1.1rem;
        }

        .generated-at {
            color: #94a3b8;
            font-size: 0.9rem;
            margin-top: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        .section {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .section h2 {
            color: var(--text-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            font-weight: 600;
            color: #64748b;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        tr:hover {
            background-color: #f1f5f9;
        }

        .score {
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }

        .score-excellent {
            background-color: #dcfce7;
            color: var(--success-color);
        }

        .score-good {
            background-color: #fef9c3;
            color: var(--warning-color);
        }

        .score-fair {
            background-color: #fee2e2;
            color: var(--danger-color);
        }

        .score-poor {
            background-color: #fce7f3;
            color: #be185d;
        }

        .top-performers {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .medal-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
        }

        .medal {
            font-size: 3rem;
        }

        .medal-info h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            word-break: break-all;
        }

        .medal-score {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .breakdown-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }

        .breakdown-item {
            text-align: center;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
        }

        .breakdown-value {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .breakdown-label {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: #94a3b8;
        }

        .footer {
            text-align: center;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
            color: #94a3b8;
        }

        /* Expandable details */
        .expandable-row {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .expandable-row:hover {
            background-color: #f1f5f9 !important;
        }

        .expandable-row.expanded {
            background-color: #e0f2fe !important;
        }

        .detail-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background-color: #e2e8f0;
            color: #64748b;
            font-size: 12px;
            transition: all 0.2s ease;
        }

        .expandable-row:hover .detail-toggle,
        .expandable-row.expanded .detail-toggle {
            background-color: var(--primary-color);
            color: white;
        }

        .detail-row {
            display: none;
            background-color: #f8fafc;
        }

        .detail-row.active {
            display: table-row;
        }

        .detail-content {
            padding: 1.5rem 2rem;
            background-color: #f8fafc;
            border-bottom: 1px solid var(--border-color);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .detail-section {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .detail-section h4 {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .detail-section.logic h4 {
            color: var(--success-color);
            border-bottom-color: #bbf7d0;
        }

        .detail-section.syntax h4 {
            color: var(--primary-color);
            border-bottom-color: #bfdbfe;
        }

        .detail-section.output h4 {
            color: var(--warning-color);
            border-bottom-color: #fef08a;
        }

        .detail-section ul {
            list-style: none;
            padding: 0;
        }

        .detail-section li {
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
            color: var(--text-color);
        }

        .detail-section li:last-child {
            border-bottom: none;
        }

        .feedback-text {
            font-size: 0.9rem;
            color: var(--text-color);
            line-height: 1.6;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 6px;
            border-left: 3px solid var(--primary-color);
        }

        .strengths-list li::before {
            content: "✓ ";
            color: var(--success-color);
            font-weight: bold;
        }

        .weaknesses-list li::before {
            content: "✗ ";
            color: var(--danger-color);
            font-weight: bold;
        }

        .suggestions-list li::before {
            content: "💡 ";
            color: var(--warning-color);
        }

        .toggle-hint {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-left: 0.5rem;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .breakdown-grid {
                grid-template-columns: 1fr;
            }

            body {
                padding: 1rem;
            }

            th, td {
                padding: 0.75rem 0.5rem;
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>LLM Model Evaluation Report</h1>
            <p class="subtitle">Comprehensive analysis of model performance</p>
            <p class="generated-at">Generated at: {$generatedAt}</p>
        </header>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">{$totalModels}</div>
                <div class="stat-label">Total Models</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{$evaluatedCount}</div>
                <div class="stat-label">Evaluated Models</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{$averageScore}%</div>
                <div class="stat-label">Average Score</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{$highestScore}%</div>
                <div class="stat-label">Highest Score</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{$totalTokens}</div>
                <div class="stat-label">Total Tokens</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$ {$totalCost}</div>
                <div class="stat-label">Total Cost</div>
            </div>
        </div>

HTML;

        // Top performers section
        $topModels = array_filter($models, fn($m) => $m['overall_score'] > 0);
        if (count($topModels) >= 3) {
            $top3 = array_slice($topModels, 0, 3);
            $medals = ['🥇', '🥈', '🥉'];

            $html .= '
        <div class="section">
            <h2>Top Performers</h2>
            <div class="top-performers">';

            foreach ($top3 as $index => $model) {
                $modelId = htmlspecialchars($model['model_id']);
                $score = number_format($model['overall_score'], 1);
                $html .= '
                <div class="medal-card">
                    <div class="medal">' . $medals[$index] . '</div>
                    <div class="medal-info">
                        <h3>' . $modelId . '</h3>
                        <div class="medal-score">' . $score . '%</div>
                    </div>
                </div>';
            }

            $html .= '
            </div>
        </div>';
        }

        // Average criterion breakdown
        if ($stats['evaluated_count'] > 0) {
            $avgLogic = number_format($stats['avg_logic'], 1);
            $avgSyntax = number_format($stats['avg_syntax'], 1);
            $avgOutput = number_format($stats['avg_output'], 1);

            $html .= '
        <div class="section">
            <h2>Average Criterion Breakdown</h2>
            <div class="breakdown-grid">
                <div class="breakdown-item">
                    <div class="breakdown-value" style="color: var(--success-color);">' . $avgLogic . '%</div>
                    <div class="breakdown-label">Logic Score</div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-value" style="color: var(--primary-color);">' . $avgSyntax . '%</div>
                    <div class="breakdown-label">Syntax Score</div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-value" style="color: var(--warning-color);">' . $avgOutput . '%</div>
                    <div class="breakdown-label">Output Score</div>
                </div>
            </div>
        </div>';
        }

        // Model rankings table
        $html .= '
        <div class="section">
            <h2>Model Rankings</h2>';

        if (empty($models)) {
            $html .= '
            <div class="no-data">
                <p>No models have been evaluated yet.</p>
                <p>Run tests to see model rankings.</p>
            </div>';
        } else {
            $html .= '
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Model ID</th>
                        <th>Overall Score</th>
                        <th>Logic</th>
                        <th>Syntax</th>
                        <th>Output</th>
                        <th>Tests</th>
                        <th>Total Cost</th>
                    </tr>
                </thead>
                <tbody>';

            $rank = 1;
            foreach ($models as $model) {
                $scoreClass = $this->getScoreClass($model['overall_score']);
                $modelId = htmlspecialchars($model['model_id']);
                $overallScore = number_format($model['overall_score'], 1);
                $logicScore = number_format($model['logic_score'], 1);
                $syntaxScore = number_format($model['syntax_score'], 1);
                $outputScore = number_format($model['output_score'], 1);
                $testCount = $model['test_count'];
                $cost = number_format($model['total_cost'], 4);

                // Generate detail content for this model
                $detailContent = $this->generateDetailContent($model);

                $html .= '
                    <tr class="expandable-row" onclick="toggleDetails(this)">
                        <td>#' . $rank . '<span class="toggle-hint">▼</span></td>
                        <td>' . $modelId . '</td>
                        <td><span class="score ' . $scoreClass . '">' . $overallScore . '%</span></td>
                        <td>' . $logicScore . '%</td>
                        <td>' . $syntaxScore . '%</td>
                        <td>' . $outputScore . '%</td>
                        <td>' . $testCount . '</td>
                        <td>$ ' . $cost . '</td>
                    </tr>
                    <tr class="detail-row">
                        <td colspan="8" class="detail-content">
                            ' . $detailContent . '
                        </td>
                    </tr>';
                $rank++;
            }

            $html .= '
                </tbody>
            </table>';
        }

        // Add JavaScript for expandable rows
        $html .= '
        <script>
        function toggleDetails(row) {
            const detailRow = row.nextElementSibling;
            const toggleHint = row.querySelector(".toggle-hint");

            if (detailRow && detailRow.classList.contains("detail-row")) {
                detailRow.classList.toggle("active");
                row.classList.toggle("expanded");
                if (toggleHint) {
                    toggleHint.textContent = detailRow.classList.contains("active") ? "▲" : "▼";
                }
            }
        }
        </script>
        </div>

        <footer class="footer">
            <p>Report generated by LLM Model Scoring Application</p>
            <p>Generated at: ' . $generatedAt . '</p>
        </footer>
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * Generate detailed content for expandable section
     */
    private function generateDetailContent(array $model): string
    {
        // Get full evaluation data
        $evaluation = $this->getFullEvaluationData($model['model_id']);

        if (empty($evaluation)) {
            return '<p class="no-data">No detailed evaluation data available.</p>';
        }

        $eval = $evaluation['evaluation'] ?? [];
        $logic = $eval['logic'] ?? [];
        $syntax = $eval['syntax'] ?? [];
        $output = $eval['output'] ?? [];

        $logicScore = number_format($logic['score'] ?? 0, 1);
        $logicFeedback = htmlspecialchars($logic['feedback'] ?? 'No feedback available.');

        $syntaxScore = number_format($syntax['score'] ?? 0, 1);
        $syntaxFeedback = htmlspecialchars($syntax['feedback'] ?? 'No feedback available.');

        $outputScore = number_format($output['score'] ?? 0, 1);
        $outputFeedback = htmlspecialchars($output['feedback'] ?? 'No feedback available.');

        $strengths = $eval['strengths'] ?? [];
        $weaknesses = $eval['weaknesses'] ?? [];
        $suggestions = $eval['suggestions'] ?? [];

        $strengthsHtml = '';
        foreach ($strengths as $strength) {
            $strengthsHtml .= '<li>' . htmlspecialchars($strength) . '</li>';
        }

        $weaknessesHtml = '';
        foreach ($weaknesses as $weakness) {
            $weaknessesHtml .= '<li>' . htmlspecialchars($weakness) . '</li>';
        }

        $suggestionsHtml = '';
        foreach ($suggestions as $suggestion) {
            $suggestionsHtml .= '<li>' . htmlspecialchars($suggestion) . '</li>';
        }

        return '
            <div class="detail-grid">
                <div class="detail-section logic">
                    <h4>Logic Score: ' . $logicScore . '%</h4>
                    <p class="feedback-text">' . $logicFeedback . '</p>
                </div>
                <div class="detail-section syntax">
                    <h4>Syntax Score: ' . $syntaxScore . '%</h4>
                    <p class="feedback-text">' . $syntaxFeedback . '</p>
                </div>
                <div class="detail-section output">
                    <h4>Output Score: ' . $outputScore . '%</h4>
                    <p class="feedback-text">' . $outputFeedback . '</p>
                </div>
            </div>
            <div class="detail-grid" style="margin-top: 1.5rem;">
                <div class="detail-section">
                    <h4>Strengths</h4>
                    <ul class="strengths-list">
                        ' . ($strengthsHtml ?: '<li>No strengths recorded.</li>') . '
                    </ul>
                </div>
                <div class="detail-section">
                    <h4>Weaknesses</h4>
                    <ul class="weaknesses-list">
                        ' . ($weaknessesHtml ?: '<li>No weaknesses recorded.</li>') . '
                    </ul>
                </div>
                <div class="detail-section">
                    <h4>Suggestions</h4>
                    <ul class="suggestions-list">
                        ' . ($suggestionsHtml ?: '<li>No suggestions recorded.</li>') . '
                    </ul>
                </div>
            </div>
        ';
    }

    /**
     * Get full evaluation data for a model
     */
    private function getFullEvaluationData(string $modelId): ?array
    {
        $modelPath = $this->storageManager->getModelPathFromId($modelId);

        if (!is_dir($modelPath)) {
            return null;
        }

        $latestEval = null;
        $latestTimestamp = '';

        $files = scandir($modelPath);
        foreach ($files as $file) {
            if (preg_match('/^(\d+)_evaluation\.json$/', $file, $matches)) {
                $content = json_decode(file_get_contents("{$modelPath}/{$file}"), true);
                $timestamp = $content['timestamp'] ?? '';

                if ($timestamp > $latestTimestamp) {
                    $latestTimestamp = $timestamp;
                    $latestEval = $content;
                }
            }
        }

        return $latestEval;
    }

    /**
     * Gather evaluation data for a single model
     */
    private function gatherModelEvaluationData(string $modelId): ?array
    {
        $modelPath = $this->storageManager->getModelPathFromId($modelId);

        if (!is_dir($modelPath)) {
            return null;
        }

        $totalTokens = 0;
        $totalCost = 0.0;
        $latestEval = null;
        $testCount = 0;

        $files = scandir($modelPath);
        foreach ($files as $file) {
            if (preg_match('/^(\d+)_raw_response\.json$/', $file, $matches)) {
                $testCount++;
                $content = json_decode(file_get_contents("{$modelPath}/{$file}"), true);
                $usage = $content['response']['usage'] ?? [];
                $totalTokens += (int) ($usage['total_tokens'] ?? 0);
                $totalCost += (float) ($usage['cost'] ?? 0);
            }

            if (preg_match('/^(\d+)_evaluation\.json$/', $file, $matches)) {
                $content = json_decode(file_get_contents("{$modelPath}/{$file}"), true);
                $eval = $content['evaluation'] ?? [];

                if ($latestEval === null || ($content['timestamp'] ?? '') > ($latestEval['timestamp'] ?? '')) {
                    $latestEval = [
                        'timestamp' => $content['timestamp'] ?? null,
                        'overall_score' => $eval['overall_score'] ?? 0,
                        'logic_score' => $eval['logic']['score'] ?? 0,
                        'syntax_score' => $eval['syntax']['score'] ?? 0,
                        'output_score' => $eval['output']['score'] ?? 0,
                    ];
                }
            }
        }

        return [
            'model_id' => $modelId,
            'test_count' => $testCount,
            'overall_score' => $latestEval['overall_score'] ?? 0,
            'logic_score' => $latestEval['logic_score'] ?? 0,
            'syntax_score' => $latestEval['syntax_score'] ?? 0,
            'output_score' => $latestEval['output_score'] ?? 0,
            'total_tokens' => $totalTokens,
            'total_cost' => $totalCost,
        ];
    }

    /**
     * Calculate overall statistics
     */
    private function calculateStatistics(array $modelIds): array
    {
        $stats = [
            'total_models' => count($modelIds),
            'evaluated_count' => 0,
            'average_score' => 0,
            'highest_score' => 0,
            'lowest_score' => 100,
            'avg_logic' => 0,
            'avg_syntax' => 0,
            'avg_output' => 0,
            'total_tokens' => 0,
            'total_cost' => 0.0,
        ];

        $scores = [];
        $logicScores = [];
        $syntaxScores = [];
        $outputScores = [];

        foreach ($modelIds as $modelId) {
            $modelData = $this->gatherModelEvaluationData($modelId);
            if ($modelData !== null && $modelData['overall_score'] > 0) {
                $stats['evaluated_count']++;
                $scores[] = $modelData['overall_score'];
                $logicScores[] = $modelData['logic_score'];
                $syntaxScores[] = $modelData['syntax_score'];
                $outputScores[] = $modelData['output_score'];
                $stats['total_tokens'] += $modelData['total_tokens'];
                $stats['total_cost'] += $modelData['total_cost'];
            }
        }

        if (!empty($scores)) {
            $stats['average_score'] = array_sum($scores) / count($scores);
            $stats['highest_score'] = max($scores);
            $stats['lowest_score'] = min($scores);
            $stats['avg_logic'] = array_sum($logicScores) / count($logicScores);
            $stats['avg_syntax'] = array_sum($syntaxScores) / count($syntaxScores);
            $stats['avg_output'] = array_sum($outputScores) / count($outputScores);
        }

        return $stats;
    }

    /**
     * Get CSS class based on score
     */
    private function getScoreClass(float $score): string
    {
        if ($score >= 80) {
            return 'score-excellent';
        } elseif ($score >= 60) {
            return 'score-good';
        } elseif ($score >= 40) {
            return 'score-fair';
        } else {
            return 'score-poor';
        }
    }
}
