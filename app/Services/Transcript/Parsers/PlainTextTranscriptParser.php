<?php

namespace App\Services\Transcript\Parsers;

use App\Domain\DTO\TranscriptEntryDTO;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\TranscriptFormat;

/**
 * Parses plain-text transcripts.
 *
 * Two dialects supported:
 *  - Flat:        "Speaker: text"                     (no timing — DTO timings are null)
 *  - Timestamped: "[14:00] Speaker: text"             (HH:MM or HH:MM:SS prefix gives start_relative)
 *
 * `#`-prefixed lines are treated as comments and ignored (matches the Wanda
 * team's own export format under /home/b/vanda/transcript_*.txt).
 *
 * Splits on the FIRST `:` to allow names that themselves contain colons after
 * the first split point.
 */
class PlainTextTranscriptParser implements TranscriptFormatParserInterface
{
    /** Matches `[HH:MM] Speaker: text` or `[HH:MM:SS] Speaker: text`. */
    private const TIMESTAMPED_PATTERN = '/^\[(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?\]\s*(.+)$/u';

    public function parse(string $contents): array
    {
        $lines = preg_split('/\n/u', $contents) ?: [];

        $speakerSet = [];
        $entries    = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$speaker, $text, $startRelative] = $this->extractParts($line);

            if ($speaker === null || $text === '') {
                continue;
            }

            $speakerSet[$speaker] = true;

            $entries[] = new TranscriptEntryDTO(
                speaker: $speaker,
                paragraph: $text,
                startRelative: $startRelative,
                startAbsolute: null,
                endRelative: $startRelative,
                endAbsolute: null,
                durationSeconds: null,
            );
        }

        if ($entries === []) {
            throw new TranscriptParseException('No transcript entries found', TranscriptFormat::TXT);
        }

        return [
            'speakers' => array_keys($speakerSet),
            'entries'  => $entries,
        ];
    }

    public function format(): TranscriptFormat
    {
        return TranscriptFormat::TXT;
    }

    /**
     * @return array{0: ?string, 1: string, 2: ?float}  [speaker, text, start_relative_seconds]
     */
    private function extractParts(string $line): array
    {
        $startRelative = null;

        if (preg_match(self::TIMESTAMPED_PATTERN, $line, $matches)) {
            $hours   = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = isset($matches[3]) ? (int) $matches[3] : 0;
            $startRelative = (float) ($hours * 3600 + $minutes * 60 + $seconds);
            $line = $matches[4];
        }

        $colonPos = mb_strpos($line, ':');
        if ($colonPos === false) {
            return [null, '', null];
        }

        $speaker = trim(mb_substr($line, 0, $colonPos));
        $text    = trim(mb_substr($line, $colonPos + 1));

        if ($speaker === '') {
            return [null, '', null];
        }

        return [$speaker, $text, $startRelative];
    }
}
