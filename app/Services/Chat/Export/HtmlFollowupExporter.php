<?php

namespace App\Services\Chat\Export;

use App\Models\Followup;

class HtmlFollowupExporter extends AbstractFollowupExporter
{
    public function export(Followup $followup): string
    {
        $data = $this->prepareData($followup);

        return view('followup.export.html', [
            'followup'     => $followup,
            'data'         => $data['data'] ?? [],
            'metrics'      => $this->extractMetrics($data),
            'totalScore'   => $this->extractTotalScore($data),
            'strengths'    => $this->extractStrengths($data),
            'areas'        => $this->extractAreasForDevelopment($data),
            'actionPlan'   => $this->extractActionPlan($data),
        ])->render();
    }

    public function getContentType(): string
    {
        return 'text/html';
    }

    public function getFileExtension(): string
    {
        return 'html';
    }
}
