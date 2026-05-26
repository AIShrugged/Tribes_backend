<?php

namespace App\Services\Transcript;

use App\Domain\DTO\AI\MessageDTO;
use App\Domain\DTO\Transcript\TranscriptParseSpec;
use App\Models\Setting;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

/**
 * Single-shot LLM call: take a content sample, return a structured
 * TranscriptParseSpec or null on any failure (LLM error, malformed JSON,
 * schema mismatch).
 *
 * Never logs the content itself — only hashes and outcome bool — so this stays
 * PII-clean. See plan D-NO-HEADER-METADATA + D-PII-LOGS for the policy.
 */
class TranscriptSpecLlmClient
{
    private const MAX_TOKENS = 2048;
    private const BYTE_CAP = 16 * 1024;
    private const LINE_CAP = 80;

    public function __construct(
        private readonly OpenRouterClient $llm,
    ) {
    }

    public function modelId(): string
    {
        return (string) Setting::get(
            'model.transcript_format_detector',
            config('ai.providers.openrouter.models.transcript_format_detector'),
        );
    }

    /**
     * Capped input that we'll actually send to the LLM. Used both for the call
     * itself and for the cache key (so cache key is deterministic w.r.t. input).
     */
    public function capInput(string $contents): string
    {
        $lines = preg_split('/\n/u', $contents, self::LINE_CAP + 1) ?: [];
        $sample = implode("\n", array_slice($lines, 0, self::LINE_CAP));

        if (strlen($sample) > self::BYTE_CAP) {
            $sample = substr($sample, 0, self::BYTE_CAP) . "\n[...truncated]";
        }

        return $sample;
    }

    public function detectSpec(string $sample): ?TranscriptParseSpec
    {
        try {
            $json = $this->llm->chat(
                messages: [
                    new MessageDTO('system', $this->buildSystemPrompt()),
                    new MessageDTO('user', $sample),
                ],
                model: $this->modelId(),
                maxTokens: self::MAX_TOKENS,
                forceJsonResponse: true,
            );
        } catch (\Throwable $e) {
            Log::warning('transcript_pattern_detector.llm_failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $decoded = $this->decode($json);
        if ($decoded === null) {
            Log::warning('transcript_pattern_detector.malformed_response');
            return null;
        }

        return $this->parseAndValidate($decoded);
    }

    /**
     * Strip any preamble Gemini-3-pro tends to emit before the JSON body, then decode.
     * Pattern is canonical project-wide (see memory + IssueExtractionService).
     */
    private function decode(string|array|null $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return null;
        }
        if (preg_match('/\{[\s\S]*\}/s', $raw, $m)) {
            $raw = $m[0];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function parseAndValidate(array $decoded): ?TranscriptParseSpec
    {
        $spec = $decoded['spec'] ?? null;
        if (!is_array($spec)) {
            return null;
        }

        $speakerExtraction = $spec['speaker_extraction'] ?? null;
        $speakerDelimiter  = $spec['speaker_delimiter'] ?? null;
        $timestampFormat   = $spec['timestamp_format'] ?? null;
        $timestampLocation = $spec['timestamp_location'] ?? null;
        $exampleLine       = $spec['example_line'] ?? null;

        if (!$this->isValidEnum($speakerExtraction, TranscriptParseSpec::VALID_SPEAKER_EXTRACTION)) return null;
        if (!$this->isValidEnum($speakerDelimiter, TranscriptParseSpec::VALID_SPEAKER_DELIMITERS, allowNull: true)) return null;
        if (!$this->isValidEnum($timestampFormat, TranscriptParseSpec::VALID_TIMESTAMP_FORMAT)) return null;
        if (!$this->isValidEnum($timestampLocation, TranscriptParseSpec::VALID_TIMESTAMP_LOCATION)) return null;
        if (!is_string($exampleLine) || trim($exampleLine) === '') return null;

        $headerLineCount = $spec['header_line_count'] ?? null;
        if ($headerLineCount !== null && (!is_int($headerLineCount) || $headerLineCount < 0 || $headerLineCount > 50)) {
            return null;
        }

        $headerTerminator = $spec['header_terminator'] ?? null;
        if (!$this->isValidEnum($headerTerminator, TranscriptParseSpec::VALID_HEADER_TERMINATORS, allowNull: true)) {
            return null;
        }

        $commentPrefixes = $spec['comment_prefixes'] ?? [];
        if (!is_array($commentPrefixes)) {
            return null;
        }
        $commentPrefixes = array_values(array_filter(
            $commentPrefixes,
            fn ($p) => is_string($p) && $p !== '' && strlen($p) <= 4,
        ));

        $skipEmptyLines = (bool) ($spec['skip_empty_lines'] ?? true);

        return new TranscriptParseSpec(
            speakerExtraction: $speakerExtraction,
            speakerDelimiter: $speakerDelimiter,
            timestampFormat: $timestampFormat,
            timestampLocation: $timestampLocation,
            headerLineCount: $headerLineCount,
            headerTerminator: $headerTerminator,
            commentPrefixes: $commentPrefixes,
            skipEmptyLines: $skipEmptyLines,
            exampleLine: $exampleLine,
        );
    }

    private function isValidEnum(mixed $value, array $allowlist, bool $allowNull = false): bool
    {
        if ($value === null) {
            return $allowNull || in_array(null, $allowlist, true);
        }
        return in_array($value, $allowlist, true);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a transcript format detector. The user message is the first ~16KB of a
transcript file in an UNKNOWN format. Your job: identify the structure and emit
a strict JSON spec that our parser will use to extract speaker/text/timestamp
entries.

Rules:
1. NEVER write regex. Pick enum values from the schema below.
2. If the file has a header (meeting title, date, participant list, etc),
   tell us either `header_line_count` (preferred, exact integer) or
   `header_terminator` ("empty_line" or "first_match"). Headers are NOT entries.
3. `example_line` MUST be a real entry line copied verbatim from the input.
   Our parser will build a regex from your spec and verify it parses example_line.
4. Output ONLY a JSON object matching the schema. No prose outside `reasoning`,
   no markdown fences.

Schema:
{
  "format": "custom_txt",
  "spec": {
    "speaker_extraction": "before_colon" | "in_brackets" | "voice_xml_tag" | "line_prefix_paren",
    "speaker_delimiter": ":" | "—" | "->" | ">" | null,
    "timestamp_format": "none" | "hh_mm" | "hh_mm_ss" | "hh_mm_ss_ms" | "iso_8601",
    "timestamp_location": "none" | "line_prefix_bracketed" | "line_prefix_paren" | "after_speaker",
    "header_line_count": <int 0..50> | null,
    "header_terminator": "first_match" | "empty_line" | null,
    "comment_prefixes": ["#"] | [],
    "skip_empty_lines": true | false,
    "example_line": "<exact text of one representative entry line>"
  },
  "confidence": "high" | "medium" | "low",
  "reasoning": "<one paragraph explaining your choices>"
}

Calibration examples:

Input:
  [14:00] Анна: Всем привет
  [14:01] Пётр: По бэкенду готово
  → speaker_extraction=before_colon, speaker_delimiter=":",
    timestamp_format=hh_mm, timestamp_location=line_prefix_bracketed,
    header_line_count=0

Input:
  Meeting: Tribes
  Date: 2026-05-25
  Participants: Anna, Pete

  (00:01:23) Anna > All ready
  (00:01:45) Pete > Confirmed
  → speaker_extraction=before_colon, speaker_delimiter=">",
    timestamp_format=hh_mm_ss, timestamp_location=line_prefix_paren,
    header_line_count=4

Input:
  WEBVTT
  [00:00:01.500] <v Alice>Hello</v>
  → speaker_extraction=voice_xml_tag, speaker_delimiter=null,
    timestamp_format=hh_mm_ss_ms, timestamp_location=line_prefix_bracketed,
    header_line_count=1
PROMPT;
    }
}
