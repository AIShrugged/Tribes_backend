<?php

namespace App\Services\Transcript\Parsers;

use App\Domain\DTO\TranscriptEntryDTO;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\TranscriptFormat;

/**
 * SubRip (SRT) parser.
 *
 *   1
 *   00:00:00,000 --> 00:00:04,000
 *   Speaker: Hello there
 *
 *   2
 *   00:00:04,000 --> 00:00:07,000
 *   Speakerless line  (speaker → "Unknown")
 *
 * Differences from VTT: uses comma decimal separator and integer cue index header.
 */
class SrtTranscriptParser implements TranscriptFormatParserInterface
{
    private const TIMESTAMP_PATTERN = '/^(\d{1,2}):(\d{2}):(\d{2}),(\d{3})\s+-->\s+(\d{1,2}):(\d{2}):(\d{2}),(\d{3})/u';

    public function parse(string $contents): array
    {
        $blocks = preg_split('/\n\n+/u', trim($contents)) ?: [];

        $speakerSet = [];
        $entries    = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $lines = preg_split('/\n/u', $block) ?: [];
            $timing = null;
            $textLines = [];

            foreach ($lines as $line) {
                if ($timing === null && preg_match(self::TIMESTAMP_PATTERN, $line, $m)) {
                    $timing = [
                        'start' => $this->toSeconds((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]),
                        'end'   => $this->toSeconds((int) $m[5], (int) $m[6], (int) $m[7], (int) $m[8]),
                    ];
                    continue;
                }
                if ($timing === null && preg_match('/^\d+$/u', trim($line))) {
                    continue;
                }
                if ($timing !== null) {
                    $textLines[] = $line;
                }
            }

            if ($timing === null || $textLines === []) {
                continue;
            }

            $joinedText = trim(implode(' ', $textLines));
            if ($joinedText === '') {
                continue;
            }

            [$speaker, $text] = $this->extractSpeaker($joinedText);

            if ($text === '') {
                continue;
            }

            $speakerSet[$speaker] = true;

            $entries[] = new TranscriptEntryDTO(
                speaker: $speaker,
                paragraph: $text,
                startRelative: $timing['start'],
                startAbsolute: null,
                endRelative: $timing['end'],
                endAbsolute: null,
                durationSeconds: $timing['end'] - $timing['start'],
            );
        }

        if ($entries === []) {
            throw new TranscriptParseException('No transcript entries found', TranscriptFormat::SRT);
        }

        return [
            'speakers' => array_keys($speakerSet),
            'entries'  => $entries,
        ];
    }

    public function format(): TranscriptFormat
    {
        return TranscriptFormat::SRT;
    }

    /**
     * @return array{0: string, 1: string}  [speaker, text]
     */
    private function extractSpeaker(string $text): array
    {
        $colonPos = mb_strpos($text, ':');
        if ($colonPos === false) {
            return ['Unknown', $text];
        }

        $speaker = trim(mb_substr($text, 0, $colonPos));
        $body    = trim(mb_substr($text, $colonPos + 1));

        // Heuristic: speaker labels are short, single-line. If "speaker" looks
        // like a sentence (>40 chars or contains common punctuation), treat the
        // whole line as speakerless body.
        if ($speaker === '' || mb_strlen($speaker) > 40 || preg_match('/[.!?]/u', $speaker)) {
            return ['Unknown', $text];
        }

        return [$speaker, $body];
    }

    private function toSeconds(int $h, int $m, int $s, int $ms): float
    {
        return $h * 3600 + $m * 60 + $s + $ms / 1000;
    }
}
