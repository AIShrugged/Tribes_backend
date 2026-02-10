<?php

namespace App\Domain\DTO\Chat;

class ChartConfigDTO
{
    public const TYPE_LINE = 'line';
    public const TYPE_BAR = 'bar';
    public const TYPE_PIE = 'pie';
    public const TYPE_TABLE = 'table';
    public const TYPE_METRIC = 'metric';
    public const TYPE_NONE = 'none';

    public function __construct(
        public string $type = self::TYPE_NONE,
        public string $title = '',
        public array $xAxis = [],
        public array $yAxis = [],
        public array $series = [],
        public array $options = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? self::TYPE_NONE,
            title: $data['title'] ?? '',
            xAxis: $data['x_axis'] ?? $data['xAxis'] ?? [],
            yAxis: $data['y_axis'] ?? $data['yAxis'] ?? [],
            series: $data['series'] ?? [],
            options: $data['options'] ?? [],
        );
    }

    public function hasVisualization(): bool
    {
        return $this->type !== self::TYPE_NONE;
    }

    public function getWidth(): int
    {
        return $this->options['width'] ?? 600;
    }

    public function getHeight(): int
    {
        return $this->options['height'] ?? 300;
    }

    public function getColors(): array
    {
        return $this->options['colors'] ?? [
            '#3498db', '#e74c3c', '#2ecc71', '#f39c12',
            '#9b59b6', '#1abc9c', '#34495e', '#e67e22',
        ];
    }
}
