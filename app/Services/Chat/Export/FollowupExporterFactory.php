<?php

namespace App\Services\Chat\Export;

use InvalidArgumentException;

class FollowupExporterFactory
{
    public function make(string $format): AbstractFollowupExporter
    {
        return match ($format) {
            'pdf'   => new PdfFollowupExporter(),
            'excel' => new ExcelFollowupExporter(),
            'html'  => new HtmlFollowupExporter(),
            default => throw new InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }
}
