<?php

namespace App\Services\Transcript\Parsers;

use App\Services\Transcript\Exceptions\UnsupportedFormatException;
use App\Services\Transcript\TranscriptFormat;

class TranscriptParserRegistry
{
    public function __construct(
        private readonly RecallJsonTranscriptParser $recallJson,
        private readonly PlainTextTranscriptParser $plainText,
        private readonly VttTranscriptParser $vtt,
        private readonly SrtTranscriptParser $srt,
    ) {
    }

    public function forFormat(TranscriptFormat $format): TranscriptFormatParserInterface
    {
        return match ($format) {
            TranscriptFormat::RECALL_JSON => $this->recallJson,
            TranscriptFormat::TXT         => $this->plainText,
            TranscriptFormat::VTT         => $this->vtt,
            TranscriptFormat::SRT         => $this->srt,
        };
    }
}
