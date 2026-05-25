<?php

namespace App\Services\Transcript;

class TranscriptContentNormalizer
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Strip UTF-8 BOM and normalise line endings to LF.
     *
     * Without BOM stripping, `json_decode` on a Recall file would silently fail and
     * the detector would fall through to TXT, garbling the upload. Without line-ending
     * normalisation, Mac-only `\r` breaks both VTT and SRT cue-block splitting.
     */
    public function normalize(string $contents): string
    {
        if (str_starts_with($contents, self::UTF8_BOM)) {
            $contents = substr($contents, strlen(self::UTF8_BOM));
        }

        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        return $contents;
    }
}
