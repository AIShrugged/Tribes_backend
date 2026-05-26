<?php

namespace App\Services\Transcript\Parsers;

use App\Domain\DTO\Transcript\TranscriptParseSpec;
use App\Domain\DTO\TranscriptEntryDTO;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\TranscriptFormat;

/**
 * Line-based parser built from a {@see TranscriptParseSpec}.
 *
 * All regex patterns are constructed in this file from spec enum values — the LLM
 * never writes raw regex (see plan D-SPEC-ENUM). Header lines are skipped per
 * spec.headerLineCount (preferred) or headerTerminator (fallback).
 *
 * Lines that don't match the entry pattern are SOFTLY SKIPPED — the parser reports
 * a yield ratio so the caller can reject specs that match too little of the file.
 */
class GenericLineBasedTranscriptParser implements TranscriptFormatParserInterface
{
    private string $entryRegex;

    public function __construct(private readonly TranscriptParseSpec $spec)
    {
        $this->entryRegex = $this->buildEntryRegex($spec);
    }

    public function parse(string $contents): array
    {
        $lines = preg_split('/\n/u', $contents) ?: [];
        $bodyLines = $this->stripHeader($lines);

        [$entries, $speakerSet, $consideredCount] = $this->parseLines($bodyLines);

        if ($entries === []) {
            throw new TranscriptParseException(
                'GenericLineBasedTranscriptParser yielded zero entries',
                TranscriptFormat::TXT,
            );
        }

        return [
            'speakers' => array_keys($speakerSet),
            'entries' => $entries,
            // Caller (TranscriptUploadService) uses this to enforce the >=60% gate.
            '_meta' => [
                'considered_lines' => $consideredCount,
                'parsed_entries' => count($entries),
                'yield_ratio' => $consideredCount > 0 ? count($entries) / $consideredCount : 0.0,
            ],
        ];
    }

    public function format(): TranscriptFormat
    {
        // Generic parser uses TXT in the enum since we never added a GENERIC case
        // (would poison exhaustive `match` blocks in the registry). The actual
        // distinction lives in telemetry / logs, not the enum.
        return TranscriptFormat::TXT;
    }

    /**
     * Verify the regex built from this spec successfully parses spec.exampleLine.
     * Used by the pattern detector as a cheap pre-cache gate.
     */
    public function canParseExampleLine(): bool
    {
        return $this->matchLine($this->spec->exampleLine) !== null;
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Header handling
    // ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  string[]  $lines
     * @return string[]
     */
    private function stripHeader(array $lines): array
    {
        if ($this->spec->headerLineCount !== null) {
            return array_slice($lines, max(0, $this->spec->headerLineCount));
        }

        switch ($this->spec->headerTerminator) {
            case 'empty_line':
                $idx = $this->findFirstEmptyLine($lines);
                return $idx === null ? $lines : array_slice($lines, $idx + 1);

            case 'first_match':
                $idx = $this->findFirstMatchingLine($lines);
                return $idx === null ? $lines : array_slice($lines, $idx);

            default:
                return $lines;
        }
    }

    /** @param  string[]  $lines */
    private function findFirstEmptyLine(array $lines): ?int
    {
        foreach ($lines as $idx => $line) {
            if (trim($line) === '') {
                return $idx;
            }
        }
        return null;
    }

    /** @param  string[]  $lines */
    private function findFirstMatchingLine(array $lines): ?int
    {
        foreach ($lines as $idx => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if ($this->isComment($trimmed)) {
                continue;
            }
            if ($this->matchLine($trimmed) !== null) {
                return $idx;
            }
        }
        return null;
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Per-line parsing
    // ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  string[]  $bodyLines
     * @return array{0: TranscriptEntryDTO[], 1: array<string,bool>, 2: int}
     */
    private function parseLines(array $bodyLines): array
    {
        $entries = [];
        $speakerSet = [];
        $consideredCount = 0;

        foreach ($bodyLines as $rawLine) {
            $line = trim($rawLine);

            if ($this->spec->skipEmptyLines && $line === '') {
                continue;
            }
            if ($this->isComment($line)) {
                continue;
            }

            $consideredCount++;
            $parsed = $this->matchLine($line);
            if ($parsed === null) {
                continue;
            }

            $entries[] = $parsed;
            $speakerSet[$parsed->speaker] = true;
        }

        return [$entries, $speakerSet, $consideredCount];
    }

    private function isComment(string $line): bool
    {
        foreach ($this->spec->commentPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($line, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function matchLine(string $line): ?TranscriptEntryDTO
    {
        if (!preg_match($this->entryRegex, $line, $matches)) {
            return null;
        }

        $startRelative = $this->extractTimestamp($matches);
        $speaker = trim($matches['speaker'] ?? '');
        $text = trim($matches['text'] ?? '');

        if ($speaker === '' || $text === '') {
            return null;
        }

        return new TranscriptEntryDTO(
            speaker: $speaker,
            paragraph: $text,
            startRelative: $startRelative,
            startAbsolute: null,
            endRelative: $startRelative,
            endAbsolute: null,
            durationSeconds: null,
        );
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Regex construction (whitelist-driven, no LLM input here)
    // ───────────────────────────────────────────────────────────────────────────

    private function buildEntryRegex(TranscriptParseSpec $spec): string
    {
        $tsGroup = $this->buildTimestampGroup($spec);
        $speakerGroup = $this->buildSpeakerGroup($spec);

        return match (true) {
            $spec->timestampLocation === 'line_prefix_bracketed'
                || $spec->timestampLocation === 'line_prefix_paren' =>
                '/^' . $tsGroup . '\s*' . $speakerGroup . '\s*(?<text>.+)$/u',

            $spec->timestampLocation === 'after_speaker' =>
                '/^' . $speakerGroup . '\s*' . $tsGroup . '\s*(?<text>.+)$/u',

            default => // 'none' or unknown — speaker only
                '/^' . $speakerGroup . '\s*(?<text>.+)$/u',
        };
    }

    private function buildTimestampGroup(TranscriptParseSpec $spec): string
    {
        if ($spec->timestampFormat === 'none' || $spec->timestampLocation === 'none') {
            return '';
        }

        $tsBody = match ($spec->timestampFormat) {
            'hh_mm'       => '\d{1,2}:\d{2}',
            'hh_mm_ss'    => '\d{1,2}:\d{2}:\d{2}',
            'hh_mm_ss_ms' => '\d{1,2}:\d{2}:\d{2}[.,]\d{1,3}',
            'iso_8601'    => '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?',
            default       => '\S+',
        };

        return match ($spec->timestampLocation) {
            'line_prefix_bracketed' => '(?:\[(?<ts>' . $tsBody . ')\])',
            'line_prefix_paren'     => '(?:\((?<ts>' . $tsBody . ')\))',
            'after_speaker'         => '(?:\[?(?<ts>' . $tsBody . ')\]?)',
            default                 => '',
        };
    }

    private function buildSpeakerGroup(TranscriptParseSpec $spec): string
    {
        return match ($spec->speakerExtraction) {
            'in_brackets' =>
                '\[(?<speaker>[^\]\n]+)\]',

            'voice_xml_tag' =>
                '<v(?:\.[^\s>]*)?\s+(?<speaker>[^>]+)>',

            'line_prefix_paren' =>
                '\((?<speaker>[^)\n]+)\)',

            // before_colon (default): speaker terminated by spec.speakerDelimiter
            default => $this->buildBeforeDelimiterSpeaker($spec->speakerDelimiter ?? ':'),
        };
    }

    private function buildBeforeDelimiterSpeaker(string $delimiter): string
    {
        $quoted = preg_quote($delimiter, '/');
        // [^...\n]+ — non-delimiter, non-newline characters
        return '(?<speaker>[^' . $quoted . '\n]+)' . $quoted;
    }

    private function extractTimestamp(array $matches): ?float
    {
        if (!isset($matches['ts'])) {
            return null;
        }
        $raw = $matches['ts'];
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2})(?:[.,](\d{1,3}))?)?$/', $raw, $m)) {
            $hours = (int) $m[1];
            $minutes = (int) $m[2];
            $seconds = isset($m[3]) ? (int) $m[3] : 0;
            $ms = isset($m[4]) ? (int) str_pad($m[4], 3, '0', STR_PAD_RIGHT) : 0;
            return (float) ($hours * 3600 + $minutes * 60 + $seconds + $ms / 1000);
        }
        return null;
    }
}
