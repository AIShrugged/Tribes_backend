<?php

namespace App\Domain\DTO\Transcript;

use App\Domain\DTO\BaseDTO;

/**
 * Immutable parsing recipe produced by the LLM pattern detector.
 *
 * `VALID_*` const arrays act as a value allowlist — the LLM picks from these
 * enums and we never accept anything outside them. This keeps regex
 * construction (in `GenericLineBasedTranscriptParser`) entirely in our code
 * and immune to ReDoS / injection via the LLM response.
 *
 * Schema / prompt versioning lives on the constants below; bumping either
 * invalidates every cached spec (see `TranscriptSpecCache`).
 */
final class TranscriptParseSpec extends BaseDTO
{
    public const SCHEMA_VERSION = 1;
    public const PROMPT_VERSION = 1;

    public const VALID_SPEAKER_EXTRACTION = [
        'before_colon',
        'in_brackets',
        'voice_xml_tag',
        'line_prefix_paren',
    ];

    public const VALID_SPEAKER_DELIMITERS = [':', '—', '->', '>', null];

    public const VALID_TIMESTAMP_FORMAT = [
        'none',
        'hh_mm',
        'hh_mm_ss',
        'hh_mm_ss_ms',
        'iso_8601',
    ];

    public const VALID_TIMESTAMP_LOCATION = [
        'none',
        'line_prefix_bracketed',
        'line_prefix_paren',
        'after_speaker',
    ];

    public const VALID_HEADER_TERMINATORS = ['first_match', 'empty_line', null];

    public function __construct(
        public readonly string $speakerExtraction,
        public readonly ?string $speakerDelimiter,
        public readonly string $timestampFormat,
        public readonly string $timestampLocation,
        public readonly ?int $headerLineCount,
        public readonly ?string $headerTerminator,
        /** @var string[] */
        public readonly array $commentPrefixes,
        public readonly bool $skipEmptyLines,
        public readonly string $exampleLine,
    ) {
    }

    /**
     * Hydrate from a previously-cached array (round-trip via toArray()).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            speakerExtraction: $data['speakerExtraction'],
            speakerDelimiter: $data['speakerDelimiter'] ?? null,
            timestampFormat: $data['timestampFormat'],
            timestampLocation: $data['timestampLocation'],
            headerLineCount: $data['headerLineCount'] ?? null,
            headerTerminator: $data['headerTerminator'] ?? null,
            commentPrefixes: $data['commentPrefixes'] ?? [],
            skipEmptyLines: (bool) ($data['skipEmptyLines'] ?? true),
            exampleLine: $data['exampleLine'] ?? '',
        );
    }
}
