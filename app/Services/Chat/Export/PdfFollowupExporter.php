<?php

namespace App\Services\Chat\Export;

use App\Models\Followup;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfFollowupExporter extends AbstractFollowupExporter
{
    public function export(Followup $followup): string
    {
        $data = $this->prepareData($followup);

        $html = view('followup.export.pdf', [
            'followup'     => $followup,
            'data'         => $data['data'] ?? [],
            'metrics'      => $this->extractMetrics($data),
            'totalScore'   => $this->extractTotalScore($data),
            'strengths'    => $this->extractStrengths($data),
            'areas'        => $this->extractAreasForDevelopment($data),
            'actionPlan'   => $this->extractActionPlan($data),
        ])->render();

        $pdf = Pdf::loadHTML($html);

        return $pdf->output();
    }

    public function getContentType(): string
    {
        return 'application/pdf';
    }

    public function getFileExtension(): string
    {
        return 'pdf';
    }
}
