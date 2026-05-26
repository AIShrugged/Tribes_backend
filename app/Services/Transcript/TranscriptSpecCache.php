<?php

namespace App\Services\Transcript;

use App\Domain\DTO\Transcript\TranscriptParseSpec;
use Illuminate\Support\Facades\Cache;

/**
 * Versioned content-keyed cache for LLM-derived TranscriptParseSpec.
 *
 * Cache key composition (see plan D-CACHE-KEY):
 *   transcript_format_spec:v{SCHEMA_VERSION}:sha256(model_id | prompt_version | first_80_lines)
 *
 * Bumping SCHEMA_VERSION on the DTO, PROMPT_VERSION on the DTO, or the
 * configured model id all naturally yield different keys → cache miss. No
 * separate value envelope is needed.
 */
class TranscriptSpecCache
{
    private const KEY_PREFIX = 'transcript_format_spec';
    private const TTL_DAYS = 7;

    public function key(string $contentSample, string $modelId): string
    {
        $fingerprint = hash(
            'sha256',
            $modelId
            . '|' . TranscriptParseSpec::PROMPT_VERSION
            . '|' . $this->firstNLines($contentSample, 80),
        );

        return self::KEY_PREFIX . ':v' . TranscriptParseSpec::SCHEMA_VERSION . ':' . $fingerprint;
    }

    public function get(string $key): ?TranscriptParseSpec
    {
        $stored = Cache::get($key);
        if (!is_array($stored)) {
            return null;
        }
        try {
            return TranscriptParseSpec::fromArray($stored);
        } catch (\Throwable) {
            // Defensive: malformed cache entry (shouldn't happen with versioned keys,
            // but a manual cache write or a corrupted backend could). Treat as miss.
            return null;
        }
    }

    public function put(string $key, TranscriptParseSpec $spec): void
    {
        Cache::put($key, $spec->toArray(), now()->addDays(self::TTL_DAYS));
    }

    public function forget(string $key): void
    {
        Cache::forget($key);
    }

    private function firstNLines(string $contents, int $n): string
    {
        $lines = preg_split('/\n/u', $contents, $n + 1) ?: [];
        return implode("\n", array_slice($lines, 0, $n));
    }
}
