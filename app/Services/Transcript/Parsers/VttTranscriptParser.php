<?php

namespace App\Services\Transcript\Parsers;

use App\Domain\DTO\TranscriptEntryDTO;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\TranscriptFormat;

/**
 * WebVTT parser.
 *
 *   WEBVTT
 *
 *   00:00:00.000 --> 00:00:04.000
 *   <v Speaker>Hello there</v>
 *
 *   NOTE comment block — ignored
 *
 *   00:00:04.000 --> 00:00:07.000
 *   Speakerless cue text  (speaker → "Unknown")
 *
 * Multiple `<v>` tags in the same cue produce one entry per tag.
 */
class VttTranscriptParser implements TranscriptFormatParserInterface
{
    private const TIMESTAMP_PATTERN = '/^(\d{1,2}):(\d{2}):(\d{2})\.(\d{3})\s+-->\s+(\d{1,2}):(\d{2}):(\d{2})\.(\d{3})/u';
    private const VOICE_TAG_PATTERN = '/<v(?:\.[^\s>]*)?\s+([^>]+)>(.*?)(?:<\/v>|$)/u';

    public function parse(string $contents): array
    {
        $blocks = preg_split('/\n\n+/u', trim($contents)) ?: [];

        $speakerSet = [];
        $entries    = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '' || $block === 'WEBVTT' || str_starts_with($block, 'WEBVTT')) {
                continue;
            }
            if (str_starts_with($block, 'NOTE') || str_starts_with($block, 'STYLE') || str_starts_with($block, 'REGION')) {
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
                // Skip cue identifiers (digit-only lines before timestamp).
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

            $voiceMatches = $this->extractVoiceTags($joinedText);

            if ($voiceMatches === []) {
                $speaker = 'Unknown';
                $speakerSet[$speaker] = true;
                $entries[] = $this->buildEntry($speaker, $joinedText, $timing);
                continue;
            }

            foreach ($voiceMatches as [$speaker, $text]) {
                if ($text === '') {
                    continue;
                }
                $speakerSet[$speaker] = true;
                $entries[] = $this->buildEntry($speaker, $text, $timing);
            }
        }

        if ($entries === []) {
            throw new TranscriptParseException('No transcript entries found', TranscriptFormat::VTT);
        }

        return [
            'speakers' => array_keys($speakerSet),
            'entries'  => $entries,
        ];
    }

    public function format(): TranscriptFormat
    {
        return TranscriptFormat::VTT;
    }

    /**
     * @return array<int, array{0: string, 1: string}>  [[speaker, text], ...]
     */
    private function extractVoiceTags(string $text): array
    {
        if (preg_match_all(self::VOICE_TAG_PATTERN, $text, $matches, PREG_SET_ORDER)) {
            return array_map(
                fn (array $m) => [trim($m[1]), trim(strip_tags($m[2]))],
                $matches,
            );
        }

        return [];
    }

    private function toSeconds(int $h, int $m, int $s, int $ms): float
    {
        return $h * 3600 + $m * 60 + $s + $ms / 1000;
    }

    /**
     * @param  array{start: float, end: float}  $timing
     */
    private function buildEntry(string $speaker, string $text, array $timing): TranscriptEntryDTO
    {
        return new TranscriptEntryDTO(
            speaker: $speaker,
            paragraph: $text,
            startRelative: $timing['start'],
            startAbsolute: null,
            endRelative: $timing['end'],
            endAbsolute: null,
            durationSeconds: $timing['end'] - $timing['start'],
        );
    }
}
