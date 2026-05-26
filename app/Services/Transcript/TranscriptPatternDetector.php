<?php

namespace App\Services\Transcript;

use App\Domain\DTO\Transcript\TranscriptParseSpec;
use App\Services\Transcript\Parsers\GenericLineBasedTranscriptParser;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator: runs the LLM detection pipeline with cache + pre-cache validation.
 *
 * Hot path:
 *   detect($contents) →
 *     1. Build cache key from {model_id, prompt_version, schema_version, first_80_lines}.
 *     2. Cache hit (versioned): return spec, no LLM call.
 *     3. Cache miss: call LLM, validate schema, verify example_line round-trip, cache, return.
 *     4. Any failure: return null. Caller maps to 422.
 *
 * `invalidate($contents)` lets the caller drop a bad cache entry after the
 * full-content parse fails the yield check.
 */
class TranscriptPatternDetector
{
    public function __construct(
        private readonly TranscriptSpecCache $cache,
        private readonly TranscriptSpecLlmClient $llmClient,
    ) {
    }

    public function detect(string $contents): ?TranscriptParseSpec
    {
        $modelId = $this->llmClient->modelId();
        $sample = $this->llmClient->capInput($contents);
        $cacheKey = $this->cache->key($sample, $modelId);

        $startedAt = microtime(true);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $this->logTelemetry([
                'event' => 'detected',
                'cache_hit' => true,
                'content_hash' => $this->shortHash($cacheKey),
                'duration_ms' => $this->durationMs($startedAt),
            ]);
            return $cached;
        }

        $spec = $this->llmClient->detectSpec($sample);
        if ($spec === null) {
            $this->logTelemetry([
                'event' => 'detection_failed',
                'content_hash' => $this->shortHash($cacheKey),
                'duration_ms' => $this->durationMs($startedAt),
            ]);
            return null;
        }

        // Pre-cache gate: build a parser from the spec and verify the example_line
        // round-trips through our regex. If not, the LLM picked enums that don't
        // describe what they claim — reject without caching.
        if (!$this->verifyExampleLine($spec)) {
            $this->logTelemetry([
                'event' => 'example_line_gate_failed',
                'content_hash' => $this->shortHash($cacheKey),
                'duration_ms' => $this->durationMs($startedAt),
            ]);
            return null;
        }

        $this->cache->put($cacheKey, $spec);

        $this->logTelemetry([
            'event' => 'detected',
            'cache_hit' => false,
            'content_hash' => $this->shortHash($cacheKey),
            'duration_ms' => $this->durationMs($startedAt),
        ]);

        return $spec;
    }

    /**
     * Drop a cached spec for given content. Called by the upload service when
     * the full-content parse fails the yield check (so the next upload won't
     * get the same broken spec).
     */
    public function invalidate(string $contents): void
    {
        $modelId = $this->llmClient->modelId();
        $sample = $this->llmClient->capInput($contents);
        $this->cache->forget($this->cache->key($sample, $modelId));
    }

    private function verifyExampleLine(TranscriptParseSpec $spec): bool
    {
        try {
            $parser = new GenericLineBasedTranscriptParser($spec);
            return $parser->canParseExampleLine();
        } catch (\Throwable) {
            return false;
        }
    }

    private function shortHash(string $cacheKey): string
    {
        // Cache key format: "transcript_format_spec:v1:<sha256>". Return last 12
        // of the hash for log readability.
        $parts = explode(':', $cacheKey);
        $hash = end($parts);
        return is_string($hash) ? substr($hash, 0, 12) : 'unknown';
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function logTelemetry(array $payload): void
    {
        Log::info('transcript_pattern_detector', $payload);
    }
}
