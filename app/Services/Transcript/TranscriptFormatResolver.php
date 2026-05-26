<?php

namespace App\Services\Transcript;

use App\Domain\DTO\Transcript\TranscriptParseSpec;
use App\Services\Transcript\Exceptions\UnsupportedFormatException;
use App\Services\Transcript\Parsers\GenericLineBasedTranscriptParser;
use App\Services\Transcript\Parsers\TranscriptFormatParserInterface;
use App\Services\Transcript\Parsers\TranscriptParserRegistry;

/**
 * Owns the detection cascade for `TranscriptUploadService::handle()`:
 *
 *   1. Try the signature detector (Recall / TXT / VTT / SRT) → use existing parser.
 *   2. On UnsupportedFormatException, fall through to the LLM pattern detector.
 *   3. If LLM detection succeeds, build a GenericLineBasedTranscriptParser from the spec.
 *   4. If LLM detection also fails, re-throw UnsupportedFormatException so the
 *      controller surfaces 422 with TRANSCRIPT_FORMAT_UNRECOGNIZED.
 *
 * Returns a self-contained ResolvedFormat so the upload service stays linear.
 */
class TranscriptFormatResolver
{
    public function __construct(
        private readonly TranscriptFormatDetector $signatureDetector,
        private readonly TranscriptParserRegistry $registry,
        private readonly TranscriptPatternDetector $patternDetector,
    ) {
    }

    public function resolve(string $contents): ResolvedFormat
    {
        try {
            $format = $this->signatureDetector->detect($contents);
            $parser = $this->registry->forFormat($format);
            return new ResolvedFormat($parser, spec: null, format: $format->value);
        } catch (UnsupportedFormatException $signatureFailure) {
            // Fall through to LLM fallback.
        }

        $spec = $this->patternDetector->detect($contents);
        if ($spec === null) {
            throw new UnsupportedFormatException('Format could not be detected by signature or LLM fallback');
        }

        return new ResolvedFormat(
            parser: new GenericLineBasedTranscriptParser($spec),
            spec: $spec,
            format: 'generic',
        );
    }
}

/**
 * @internal Lightweight value object returned by {@see TranscriptFormatResolver::resolve()}.
 */
final class ResolvedFormat
{
    public function __construct(
        public readonly TranscriptFormatParserInterface $parser,
        public readonly ?TranscriptParseSpec $spec,
        /** 'recall_json' | 'txt' | 'vtt' | 'srt' | 'generic' — for telemetry */
        public readonly string $format,
    ) {
    }
}
