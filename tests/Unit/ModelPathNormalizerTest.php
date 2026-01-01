<?php

declare(strict_types=1);

use LLMScoring\Storage\ModelPathNormalizer;

describe('ModelPathNormalizer', function () {
    describe('normalize', function () {
        it('replaces forward slashes with underscores', function () {
            $result = ModelPathNormalizer::normalize('meta-llama/llama-3.1-8b-instruct');

            expect($result)->toBe('meta-llama_llama-3.1-8b-instruct');
            expect(strpos($result, '/'))->toBeFalse();
        });

        it('replaces backslashes with underscores', function () {
            $result = ModelPathNormalizer::normalize('model\\name');

            expect($result)->toBe('model_name');
        });

        it('replaces colons with underscores', function () {
            $result = ModelPathNormalizer::normalize('allenai_olmo-3.1-32b-think:free');

            expect($result)->toBe('allenai_olmo-3.1-32b-think_free');
            expect(strpos($result, ':'))->toBeFalse();
        });

        it('replaces all unsafe characters', function () {
            $result = ModelPathNormalizer::normalize('test*/name:with<special>chars|"and?more');

            expect(strpos($result, '*'))->toBeFalse();
            expect(strpos($result, ':'))->toBeFalse();
            expect(strpos($result, '<'))->toBeFalse();
            expect(strpos($result, '>'))->toBeFalse();
            expect(strpos($result, '"'))->toBeFalse();
            expect(strpos($result, '|'))->toBeFalse();
            expect(strpos($result, '?'))->toBeFalse();
        });

        it('handles model ID without special characters', function () {
            $result = ModelPathNormalizer::normalize('gpt-4');

            expect($result)->toBe('gpt-4');
        });

        it('limits length to 200 characters', function () {
            $longId = 'model_' . str_repeat('a', 250);
            $result = ModelPathNormalizer::normalize($longId);

            expect(strlen($result))->toBe(200);
        });
    });

    describe('denormalize', function () {
        it('replaces underscores with forward slashes', function () {
            $result = ModelPathNormalizer::denormalize('meta-llama_llama-3.1-8b-instruct');

            expect($result)->toBe('meta-llama/llama-3.1-8b-instruct');
        });

        it('replaces all underscores with slashes', function () {
            $result = ModelPathNormalizer::denormalize('a_b_c');

            expect($result)->toBe('a/b/c');
        });
    });

    describe('matches', function () {
        it('returns true when normalized name matches', function () {
            $result = ModelPathNormalizer::matches('allenai_olmo-3.1-32b-think:free', 'allenai_olmo-3.1-32b-think_free');

            expect($result)->toBeTrue();
        });

        it('returns false when names do not match', function () {
            $result = ModelPathNormalizer::matches('allenai_olmo-3.1-32b-think:free', 'other_model');

            expect($result)->toBeFalse();
        });
    });

    describe('getModelPath', function () {
        it('returns correct full path', function () {
            $result = ModelPathNormalizer::getModelPath('/data/models', 'meta-llama/llama-3.1-8b-instruct');

            expect($result)->toBe('/data/models/meta-llama_llama-3.1-8b-instruct');
        });
    });
});
