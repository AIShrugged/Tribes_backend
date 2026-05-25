<?php

namespace App\Services\Transcript\Parsers;

use App\Services\Transcript\TranscriptFormat;

interface TranscriptFormatParserInterface
{
    /**
     * Parse normalised (BOM-stripped, LF line-ending) transcript contents.
     *
     * @return array{speakers: string[], entries: \App\Domain\DTO\TranscriptEntryDTO[]}
     * @throws \App\Services\Transcript\Exceptions\TranscriptParseException
     */
    public function parse(string $contents): array;

    public function format(): TranscriptFormat;
}
