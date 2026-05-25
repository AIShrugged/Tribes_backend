<?php

namespace App\Services\Transcript;

enum TranscriptFormat: string
{
    case RECALL_JSON = 'recall_json';
    case TXT         = 'txt';
    case VTT         = 'vtt';
    case SRT         = 'srt';
}
