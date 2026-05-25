<?php

namespace App\Services\Transcript;

use App\Services\Transcript\Exceptions\UnsupportedFormatException;

/**
 * Determines transcript format from already-normalised contents (no BOM, LF line endings).
 *
 * Detection order:
 *   1. Valid JSON array whose first item has `participant.name` + `words` → Recall.
 *   2. Starts with "WEBVTT" line → VTT.
 *   3. First non-empty line is a positive integer and second line contains "-->" → SRT.
 *   4. Anything else → TXT.
 *
 * If a format-specific structure check fails (e.g. JSON parses but isn't Recall-shaped),
 * we DO NOT fall back to TXT — the upload service surfaces the error verbatim. A malformed
 * JSON would never produce coherent TXT, so hiding the parse failure is worse than reporting it.
 */
class TranscriptFormatDetector
{
    public function detect(string $contents): TranscriptFormat
    {
        $trimmed = ltrim($contents);

        if ($trimmed === '') {
            throw new UnsupportedFormatException('Empty file');
        }

        if ($trimmed[0] === '[' || $trimmed[0] === '{') {
            $decoded = json_decode($contents, true);
            if (is_array($decoded) && $this->looksLikeRecallJson($decoded)) {
                return TranscriptFormat::RECALL_JSON;
            }
        }

        $lines = preg_split('/\n/u', $trimmed) ?: [];
        $firstLine = trim($lines[0] ?? '');

        if ($firstLine === 'WEBVTT' || str_starts_with($firstLine, 'WEBVTT ') || str_starts_with($firstLine, 'WEBVTT\t')) {
            return TranscriptFormat::VTT;
        }

        if (preg_match('/^\d+$/u', $firstLine)) {
            $secondLine = trim($lines[1] ?? '');
            if (str_contains($secondLine, '-->')) {
                return TranscriptFormat::SRT;
            }
        }

        return TranscriptFormat::TXT;
    }

    private function looksLikeRecallJson(array $decoded): bool
    {
        if ($decoded === []) {
            return false;
        }
        $first = $decoded[0] ?? null;
        if (!is_array($first)) {
            return false;
        }

        $hasParticipantName = is_array($first['participant'] ?? null)
            && isset($first['participant']['name']);
        $hasWords = isset($first['words']) && is_array($first['words']);

        return $hasParticipantName && $hasWords;
    }
}
