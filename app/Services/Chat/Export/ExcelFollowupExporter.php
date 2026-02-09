<?php

namespace App\Services\Chat\Export;

use App\Models\Followup;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ExcelFollowupExporter extends AbstractFollowupExporter
{
    public function export(Followup $followup): string
    {
        $data = $this->prepareData($followup);

        $export = new FollowupExcelExport(
            $followup,
            $data,
            $this->extractMetrics($data),
            $this->extractTotalScore($data),
            $this->extractStrengths($data),
            $this->extractAreasForDevelopment($data),
            $this->extractActionPlan($data)
        );

        return Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
    }

    public function getContentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function getFileExtension(): string
    {
        return 'xlsx';
    }
}

class FollowupExcelExport implements WithMultipleSheets
{
    public function __construct(
        private Followup $followup,
        private array $data,
        private array $metrics,
        private ?float $totalScore,
        private array $strengths,
        private array $areas,
        private array $actionPlan
    ) {
    }

    public function sheets(): array
    {
        return [
            new FollowupSummarySheet($this->followup, $this->totalScore),
            new FollowupMetricsSheet($this->metrics),
            new FollowupFeedbackSheet($this->strengths, $this->areas, $this->actionPlan),
        ];
    }
}

class FollowupSummarySheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        private Followup $followup,
        private ?float $totalScore
    ) {
    }

    public function array(): array
    {
        return [
            [
                $this->followup->id,
                $this->followup->created_at->format('d.m.Y H:i'),
                $this->totalScore ?? 'N/A',
            ],
        ];
    }

    public function headings(): array
    {
        return ['ID', 'Дата', 'Общий балл'];
    }

    public function title(): string
    {
        return 'Сводка';
    }
}

class FollowupMetricsSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        private array $metrics
    ) {
    }

    public function array(): array
    {
        return array_map(function ($metric) {
            return [
                $metric['name'],
                $metric['score'],
                $metric['comment'],
            ];
        }, $this->metrics);
    }

    public function headings(): array
    {
        return ['Метрика', 'Балл', 'Комментарий'];
    }

    public function title(): string
    {
        return 'Метрики';
    }
}

class FollowupFeedbackSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        private array $strengths,
        private array $areas,
        private array $actionPlan
    ) {
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->strengths as $item) {
            $rows[] = ['Сильная сторона', $item];
        }

        foreach ($this->areas as $item) {
            $rows[] = ['Зона развития', $item];
        }

        foreach ($this->actionPlan as $item) {
            $rows[] = ['План действий', $item];
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['Категория', 'Описание'];
    }

    public function title(): string
    {
        return 'Обратная связь';
    }
}
