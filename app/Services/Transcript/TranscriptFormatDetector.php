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
 *   4. First non-empty, non-comment line matches the plain-text speaker signature → TXT.
 *   5. Anything else → throw UnsupportedFormatException. Caller may invoke an LLM-based
 *      pattern detector to recover unknown dialects.
 *
 * Note: previously TXT was a permissive catch-all. We made it an explicit signature so the
 * caller can distinguish "definitely TXT" from "no known format", enabling an LLM fallback
 * path for the second case.
 */
class TranscriptFormatDetector
{
    /**
     * Matches a candidate plain-text entry line:
     *   - optional [HH:MM] or [HH:MM:SS] or (HH:MM) prefix
     *   - one-or-more non-colon characters (the speaker)
     *   - colon
     *   - at least one non-whitespace character (the text)
     *
     * Examples accepted:
     *   "Anna: Hello"
     *   "[14:00] Анна: Привет"
     *   "(00:01:23) Pete: All set"
     *
     * Rejected:
     *   "Дата: 2026-04-08"          ← header-like; text-after-colon is short and digit-only,
     *                                  but we still match it here. Header rejection lives in
     *                                  the LLM-fallback path or the parser itself. See plan
     *                                  D-HEADER for the trade-off.
     */
    private const TXT_LINE_SIGNATURE = '/^(?:[\[\(]\d{1,2}:\d{2}(?::\d{2})?[\]\)]\s*)?[^:\n]+:\s*\S/u';

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

        if ($firstLine === 'WEBVTT' || str_starts_with($firstLine, 'WEBVTT ') || str_starts_with($firstLine, "WEBVTT\t")) {
            return TranscriptFormat::VTT;
        }

        if (preg_match('/^\d+$/u', $firstLine)) {
            $secondLine = trim($lines[1] ?? '');
            if (str_contains($secondLine, '-->')) {
                return TranscriptFormat::SRT;
            }
        }

        if ($this->matchesTxtSignature($lines)) {
            return TranscriptFormat::TXT;
        }

        throw new UnsupportedFormatException('Unknown transcript format');
    }

    /**
     * Walk the first ~50 lines looking for at least one line that fits the TXT entry
     * signature. Skipping comments and empty lines lets us tolerate file headers without
     * losing the signature for the actual entries.
     */
    private function matchesTxtSignature(array $lines): bool
    {
        $checked = 0;
        foreach ($lines as $rawLine) {
            if ($checked >= 50) {
                break;
            }
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $checked++;
            if (preg_match(self::TXT_LINE_SIGNATURE, $line)) {
                return true;
            }
        }
        return false;
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
