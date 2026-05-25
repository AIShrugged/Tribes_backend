<?php

namespace App\Services\Transcript\Parsers;

use App\Services\RecallTranscriptParser;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\TranscriptFormat;

class RecallJsonTranscriptParser implements TranscriptFormatParserInterface
{
    public function __construct(private readonly RecallTranscriptParser $recall)
    {
    }

    public function parse(string $contents): array
    {
        $decoded = json_decode($contents, true);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new TranscriptParseException(
                'Invalid Recall JSON: top-level must be a list of entries',
                TranscriptFormat::RECALL_JSON,
            );
        }

        return [
            'speakers' => $this->recall->getSpeakers($decoded),
            'entries'  => $this->recall->parse($decoded),
        ];
    }

    public function format(): TranscriptFormat
    {
        return TranscriptFormat::RECALL_JSON;
    }
}
