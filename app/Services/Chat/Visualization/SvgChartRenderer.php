<?php

namespace App\Services\Chat\Visualization;

use App\Domain\DTO\Chat\ChartConfigDTO;

class SvgChartRenderer
{
    public function render(ChartConfigDTO $config, array $data): string
    {
        return match ($config->type) {
            ChartConfigDTO::TYPE_LINE => $this->renderLineChart($config, $data),
            ChartConfigDTO::TYPE_BAR => $this->renderBarChart($config, $data),
            ChartConfigDTO::TYPE_PIE => $this->renderPieChart($config, $data),
            ChartConfigDTO::TYPE_TABLE => $this->renderTable($config, $data),
            ChartConfigDTO::TYPE_METRIC => $this->renderMetricCard($config, $data),
            default => $this->renderTable($config, $data),
        };
    }

    private function renderLineChart(ChartConfigDTO $config, array $data): string
    {
        $width = $config->getWidth();
        $height = $config->getHeight();
        $colors = $config->getColors();
        $padding = ['top' => 40, 'right' => 20, 'bottom' => 60, 'left' => 60];

        $chartWidth = $width - $padding['left'] - $padding['right'];
        $chartHeight = $height - $padding['top'] - $padding['bottom'];

        if (empty($data)) {
            return $this->renderNoData($width, $height, $config->title);
        }

        // Extract data points
        $labels = array_column($data, 'label');
        $values = array_column($data, 'value');

        if (empty($values)) {
            return $this->renderNoData($width, $height, $config->title);
        }

        $minVal = min($values);
        $maxVal = max($values);
        $range = $maxVal - $minVal ?: 1;

        // Add 10% padding to range
        $minVal = max(0, $minVal - $range * 0.1);
        $maxVal = $maxVal + $range * 0.1;
        $range = $maxVal - $minVal;

        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$width} {$height}\" style=\"max-width:100%;height:auto;\">";

        // Background
        $svg .= "<rect width=\"{$width}\" height=\"{$height}\" fill=\"#1a1a2e\"/>";

        // Title
        $svg .= "<text x=\"" . ($width / 2) . "\" y=\"25\" text-anchor=\"middle\" fill=\"#eee\" font-size=\"14\" font-weight=\"bold\">" . htmlspecialchars($config->title) . "</text>";

        // Grid lines
        $gridLines = 5;
        for ($i = 0; $i <= $gridLines; $i++) {
            $y = $padding['top'] + ($chartHeight / $gridLines) * $i;
            $svg .= "<line x1=\"{$padding['left']}\" y1=\"{$y}\" x2=\"" . ($width - $padding['right']) . "\" y2=\"{$y}\" stroke=\"#333\" stroke-width=\"1\"/>";

            $labelValue = $maxVal - ($range / $gridLines) * $i;
            $svg .= "<text x=\"" . ($padding['left'] - 5) . "\" y=\"" . ($y + 4) . "\" text-anchor=\"end\" fill=\"#888\" font-size=\"10\">" . round($labelValue, 1) . "</text>";
        }

        // Data line and points
        $points = [];
        $count = count($values);

        for ($i = 0; $i < $count; $i++) {
            $x = $padding['left'] + ($chartWidth / max($count - 1, 1)) * $i;
            $y = $padding['top'] + $chartHeight - (($values[$i] - $minVal) / $range) * $chartHeight;
            $points[] = "{$x},{$y}";

            // X-axis labels (show every nth label if too many)
            $showLabel = $count <= 10 || $i % ceil($count / 10) === 0;
            if ($showLabel && isset($labels[$i])) {
                $svg .= "<text x=\"{$x}\" y=\"" . ($height - $padding['bottom'] + 20) . "\" text-anchor=\"middle\" fill=\"#888\" font-size=\"10\" transform=\"rotate(-45 {$x} " . ($height - $padding['bottom'] + 20) . ")\">" . htmlspecialchars(substr($labels[$i], 0, 12)) . "</text>";
            }
        }

        // Draw line
        if (count($points) > 1) {
            $svg .= "<polyline points=\"" . implode(' ', $points) . "\" fill=\"none\" stroke=\"{$colors[0]}\" stroke-width=\"2\"/>";
        }

        // Draw points
        foreach ($points as $point) {
            [$px, $py] = explode(',', $point);
            $svg .= "<circle cx=\"{$px}\" cy=\"{$py}\" r=\"4\" fill=\"{$colors[0]}\"/>";
        }

        // Y-axis label
        if (!empty($config->yAxis['label'])) {
            $svg .= "<text x=\"15\" y=\"" . ($height / 2) . "\" text-anchor=\"middle\" fill=\"#888\" font-size=\"11\" transform=\"rotate(-90 15 " . ($height / 2) . ")\">" . htmlspecialchars($config->yAxis['label']) . "</text>";
        }

        $svg .= "</svg>";

        return $svg;
    }

    private function renderBarChart(ChartConfigDTO $config, array $data): string
    {
        $width = $config->getWidth();
        $height = $config->getHeight();
        $colors = $config->getColors();
        $padding = ['top' => 40, 'right' => 20, 'bottom' => 80, 'left' => 60];

        $chartWidth = $width - $padding['left'] - $padding['right'];
        $chartHeight = $height - $padding['top'] - $padding['bottom'];

        if (empty($data)) {
            return $this->renderNoData($width, $height, $config->title);
        }

        $labels = array_column($data, 'label');
        $values = array_column($data, 'value');

        $maxVal = max($values) ?: 1;
        $maxVal = $maxVal * 1.1; // Add 10% padding

        $barCount = count($values);
        $barWidth = ($chartWidth / $barCount) * 0.7;
        $barGap = ($chartWidth / $barCount) * 0.3;

        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$width} {$height}\" style=\"max-width:100%;height:auto;\">";
        $svg .= "<rect width=\"{$width}\" height=\"{$height}\" fill=\"#1a1a2e\"/>";
        $svg .= "<text x=\"" . ($width / 2) . "\" y=\"25\" text-anchor=\"middle\" fill=\"#eee\" font-size=\"14\" font-weight=\"bold\">" . htmlspecialchars($config->title) . "</text>";

        // Grid lines
        $gridLines = 5;
        for ($i = 0; $i <= $gridLines; $i++) {
            $y = $padding['top'] + ($chartHeight / $gridLines) * $i;
            $svg .= "<line x1=\"{$padding['left']}\" y1=\"{$y}\" x2=\"" . ($width - $padding['right']) . "\" y2=\"{$y}\" stroke=\"#333\" stroke-width=\"1\"/>";
            $labelValue = $maxVal - ($maxVal / $gridLines) * $i;
            $svg .= "<text x=\"" . ($padding['left'] - 5) . "\" y=\"" . ($y + 4) . "\" text-anchor=\"end\" fill=\"#888\" font-size=\"10\">" . round($labelValue, 1) . "</text>";
        }

        // Bars
        for ($i = 0; $i < $barCount; $i++) {
            $x = $padding['left'] + ($chartWidth / $barCount) * $i + $barGap / 2;
            $barHeight = ($values[$i] / $maxVal) * $chartHeight;
            $y = $padding['top'] + $chartHeight - $barHeight;

            $color = $colors[$i % count($colors)];
            $svg .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$barWidth}\" height=\"{$barHeight}\" fill=\"{$color}\" rx=\"2\"/>";

            // Value on top
            $svg .= "<text x=\"" . ($x + $barWidth / 2) . "\" y=\"" . ($y - 5) . "\" text-anchor=\"middle\" fill=\"#eee\" font-size=\"10\">" . round($values[$i], 1) . "</text>";

            // Label
            $labelX = $x + $barWidth / 2;
            $labelY = $height - $padding['bottom'] + 15;
            $svg .= "<text x=\"{$labelX}\" y=\"{$labelY}\" text-anchor=\"end\" fill=\"#888\" font-size=\"10\" transform=\"rotate(-45 {$labelX} {$labelY})\">" . htmlspecialchars(substr($labels[$i] ?? '', 0, 15)) . "</text>";
        }

        $svg .= "</svg>";

        return $svg;
    }

    private function renderPieChart(ChartConfigDTO $config, array $data): string
    {
        $width = $config->getWidth();
        $height = $config->getHeight();
        $colors = $config->getColors();

        if (empty($data)) {
            return $this->renderNoData($width, $height, $config->title);
        }

        $labels = array_column($data, 'label');
        $values = array_column($data, 'value');
        $total = array_sum($values) ?: 1;

        $centerX = $width / 2;
        $centerY = $height / 2 + 10;
        $radius = min($width, $height) / 2 - 60;

        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$width} {$height}\" style=\"max-width:100%;height:auto;\">";
        $svg .= "<rect width=\"{$width}\" height=\"{$height}\" fill=\"#1a1a2e\"/>";
        $svg .= "<text x=\"" . ($width / 2) . "\" y=\"25\" text-anchor=\"middle\" fill=\"#eee\" font-size=\"14\" font-weight=\"bold\">" . htmlspecialchars($config->title) . "</text>";

        $startAngle = -90;
        for ($i = 0; $i < count($values); $i++) {
            $percentage = $values[$i] / $total;
            $angle = $percentage * 360;

            $color = $colors[$i % count($colors)];

            if ($percentage >= 1) {
                // Full circle
                $svg .= "<circle cx=\"{$centerX}\" cy=\"{$centerY}\" r=\"{$radius}\" fill=\"{$color}\"/>";
            } else {
                $path = $this->describeArc($centerX, $centerY, $radius, $startAngle, $startAngle + $angle);
                $svg .= "<path d=\"{$path}\" fill=\"{$color}\"/>";
            }

            // Legend
            $legendY = $height - 30 + floor($i / 3) * 15;
            $legendX = 20 + ($i % 3) * (($width - 40) / 3);
            $svg .= "<rect x=\"{$legendX}\" y=\"" . ($legendY - 8) . "\" width=\"10\" height=\"10\" fill=\"{$color}\"/>";
            $svg .= "<text x=\"" . ($legendX + 15) . "\" y=\"{$legendY}\" fill=\"#888\" font-size=\"9\">" . htmlspecialchars(substr($labels[$i] ?? '', 0, 12)) . " (" . round($percentage * 100, 1) . "%)</text>";

            $startAngle += $angle;
        }

        $svg .= "</svg>";

        return $svg;
    }

    private function renderTable(ChartConfigDTO $config, array $data): string
    {
        if (empty($data)) {
            return "<div class=\"chart-no-data\"><p>Нет данных для отображения</p></div>";
        }

        $html = "<div class=\"chart-table\">";

        if ($config->title) {
            $html .= "<h4>" . htmlspecialchars($config->title) . "</h4>";
        }

        $html .= "<table><thead><tr>";

        // Headers from first row keys
        $headers = array_keys($data[0] ?? []);
        foreach ($headers as $header) {
            $html .= "<th>" . htmlspecialchars($header) . "</th>";
        }
        $html .= "</tr></thead><tbody>";

        foreach ($data as $row) {
            $html .= "<tr>";
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $html .= "<td>" . htmlspecialchars(is_array($value) ? json_encode($value) : $value) . "</td>";
            }
            $html .= "</tr>";
        }

        $html .= "</tbody></table></div>";

        return $html;
    }

    private function renderMetricCard(ChartConfigDTO $config, array $data): string
    {
        $value = $data[0]['value'] ?? $data['value'] ?? 0;
        $label = $data[0]['label'] ?? $data['label'] ?? $config->title;
        $trend = $data[0]['trend'] ?? $data['trend'] ?? null;
        $trendValue = $data[0]['trend_value'] ?? $data['trend_value'] ?? null;

        $trendIcon = match (true) {
            $trend === 'up' => '↑',
            $trend === 'down' => '↓',
            default => '',
        };

        $trendColor = match (true) {
            $trend === 'up' => '#2ecc71',
            $trend === 'down' => '#e74c3c',
            default => '#888',
        };

        $html = "<div class=\"metric-card\">";
        $html .= "<div class=\"metric-label\">" . htmlspecialchars($label) . "</div>";
        $html .= "<div class=\"metric-value\">" . htmlspecialchars($value) . "</div>";

        if ($trendValue !== null) {
            $html .= "<div class=\"metric-trend\" style=\"color:{$trendColor}\">{$trendIcon} " . htmlspecialchars($trendValue) . "</div>";
        }

        $html .= "</div>";

        return $html;
    }

    private function renderNoData(int $width, int $height, string $title): string
    {
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$width} {$height}\" style=\"max-width:100%;height:auto;\">";
        $svg .= "<rect width=\"{$width}\" height=\"{$height}\" fill=\"#1a1a2e\"/>";
        $svg .= "<text x=\"" . ($width / 2) . "\" y=\"25\" text-anchor=\"middle\" fill=\"#eee\" font-size=\"14\" font-weight=\"bold\">" . htmlspecialchars($title) . "</text>";
        $svg .= "<text x=\"" . ($width / 2) . "\" y=\"" . ($height / 2) . "\" text-anchor=\"middle\" fill=\"#888\" font-size=\"12\">Нет данных для отображения</text>";
        $svg .= "</svg>";

        return $svg;
    }

    private function describeArc(float $x, float $y, float $radius, float $startAngle, float $endAngle): string
    {
        $start = $this->polarToCartesian($x, $y, $radius, $endAngle);
        $end = $this->polarToCartesian($x, $y, $radius, $startAngle);

        $largeArcFlag = ($endAngle - $startAngle) <= 180 ? 0 : 1;

        return "M {$x} {$y} L {$start['x']} {$start['y']} A {$radius} {$radius} 0 {$largeArcFlag} 0 {$end['x']} {$end['y']} Z";
    }

    private function polarToCartesian(float $centerX, float $centerY, float $radius, float $angleInDegrees): array
    {
        $angleInRadians = ($angleInDegrees) * M_PI / 180.0;

        return [
            'x' => $centerX + ($radius * cos($angleInRadians)),
            'y' => $centerY + ($radius * sin($angleInRadians)),
        ];
    }
}
