<?php

declare(strict_types=1);

namespace LLMScoring\Storage;

/**
 * Normalizes model IDs to safe directory names
 * Ensures consistent naming across all components (StateManager, StorageManager)
 */
class ModelPathNormalizer
{
    /**
     * Characters that are not safe in directory names on any platform
     */
    private const UNSAFE_CHARS = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];

    /**
     * Normalize a model ID to a safe directory name
     * Handles model IDs like "meta-llama/llama-3.1-8b-instruct" → "meta-llama_llama-3.1-8b-instruct"
     * Handles "allenai_olmo-3.1-32b-think:free" → "allenai_olmo-3.1-32b-think_free"
     *
     * @param string $modelId The model ID to normalize
     * @return string A safe directory name
     */
    public static function normalize(string $modelId): string
    {
        $safe = str_replace(self::UNSAFE_CHARS, '_', $modelId);

        // Limit length to avoid filesystem limitations
        if (strlen($safe) > 200) {
            $safe = substr($safe, 0, 200);
        }

        return $safe;
    }

    /**
     * Check if a directory name is a normalized version of a model ID
     *
     * @param string $modelId The original model ID
     * @param string $directoryName The directory name to check
     * @return bool True if the directory name matches the normalized model ID
     */
    public static function matches(string $modelId, string $directoryName): bool
    {
        return self::normalize($modelId) === $directoryName;
    }

    /**
     * Get the directory path for a model
     *
     * @param string $basePath The base directory path
     * @param string $modelId The model ID
     * @return string The full path to the model's directory
     */
    public static function getModelPath(string $basePath, string $modelId): string
    {
        return $basePath . '/' . self::normalize($modelId);
    }

    /**
     * Reverse the normalization to get the original model ID from a directory name
     * Note: This is a best-effort reversal. Since `_` can appear in original IDs,
     * this may not always produce the exact original ID.
     *
     * @param string $directoryName The normalized directory name
     * @return string The reconstructed model ID
     */
    public static function denormalize(string $directoryName): string
    {
        // This is a simple reversal - in practice, the original model ID
        // should be stored in the state.json file for accuracy
        return str_replace('_', '/', $directoryName);
    }
}
