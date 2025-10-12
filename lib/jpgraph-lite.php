<?php
/**
 * Lightweight line chart renderer inspired by JPGraph APIs.
 */

namespace MiniJpGraph;

use RuntimeException;

final class Axis
{
    private Graph $graph;
    private string $type;
    private ?string $color = null;
    private array $tickLabels = [];
    private string $title = '';

    public function __construct(Graph $graph, string $type)
    {
        $this->graph = $graph;
        $this->type = $type;
    }

    public function SetColor(string $color): void
    {
        $this->color = $color;
    }

    public function getColor(): string
    {
        return $this->color ?? $this->graph->getAxisColor();
    }

    public function SetTickLabels(array $labels): void
    {
        $this->tickLabels = array_values($labels);
    }

    public function getTickLabels(): array
    {
        return $this->tickLabels;
    }

    public function SetTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}

final class LinePlot
{
    private array $data;
    private ?string $color = null;
    private int $weight = 3;

    public function __construct(array $data)
    {
        $this->data = array_values($data);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function SetColor(string $color): void
    {
        $this->color = $color;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function SetWeight(int $weight): void
    {
        $this->weight = max(1, $weight);
    }

    public function getWeight(): int
    {
        return $this->weight;
    }
}

final class Graph
{
    private int $width;
    private int $height;
    private array $margins = [70, 30, 50, 80]; // left, right, top, bottom
    private ?string $marginColor = null;
    private ?string $plotBackground = null;
    private string $scale = 'textlin';
    private array $plots = [];
    public Axis $xaxis;
    public Axis $yaxis;
    private string $title = '';
    private ?string $titleColor = null;
    private ?string $gridColor = null;
    private ?string $labelColor = null;
    private ?string $axisColor = null;
    private array $palette = [];

    public function __construct(int $width = 760, int $height = 320)
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD extension is required to render charts.');
        }
        $this->width = max(240, $width);
        $this->height = max(160, $height);
        $this->xaxis = new Axis($this, 'x');
        $this->yaxis = new Axis($this, 'y');
    }

    public function SetScale(string $scale): void
    {
        $this->scale = $scale;
    }

    public function SetMargin(int $left, int $right, int $top, int $bottom): void
    {
        $this->margins = [max(0, $left), max(0, $right), max(0, $top), max(0, $bottom)];
    }

    public function SetMarginColor(string $color): void
    {
        $this->marginColor = $color;
    }

    public function SetFrame(bool $show): void
    {
        // Present for API compatibility. No-op for the lightweight renderer.
    }

    public function SetBox(bool $show): void
    {
        // Present for API compatibility. No-op for the lightweight renderer.
    }

    public function SetTitle(string $title): void
    {
        $this->title = $title;
    }

    public function Add(LinePlot $plot): void
    {
        $this->plots[] = $plot;
    }

    public function Stroke(?string $filename = null): void
    {
        $img = imagecreatetruecolor($this->width, $this->height);
        imagesavealpha($img, true);
        imagealphablending($img, true);

        $marginColor = $this->allocateColor($img, $this->paletteColor('margin', $this->marginColor));
        imagefilledrectangle($img, 0, 0, $this->width, $this->height, $marginColor);

        [$left, $right, $top, $bottom] = $this->margins;
        $plotLeft = $left;
        $plotRight = $this->width - $right;
        $plotTop = $top;
        $plotBottom = $this->height - $bottom;
        if ($plotLeft >= $plotRight) {
            $plotLeft = $left;
            $plotRight = $this->width - max(10, $right);
        }
        if ($plotTop >= $plotBottom) {
            $plotTop = $top;
            $plotBottom = $this->height - max(10, $bottom);
        }

        $plotBg = $this->allocateColor($img, $this->paletteColor('plot', $this->plotBackground));
        imagefilledrectangle($img, $plotLeft, $plotTop, $plotRight, $plotBottom, $plotBg);

        $axisColor = $this->allocateColor($img, $this->xaxis->getColor());
        $gridColor = $this->allocateColor($img, $this->paletteColor('grid', $this->gridColor));
        $labelColor = $this->allocateColor($img, $this->paletteColor('labels', $this->labelColor));

        // Draw grid and Y axis labels.
        $minValue = 0.0;
        $maxValue = 100.0;
        $dataPresent = false;
        foreach ($this->plots as $plot) {
            foreach ($plot->getData() as $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $dataPresent = true;
                $numeric = (float) $value;
                $minValue = min($minValue, $numeric);
                $maxValue = max($maxValue, $numeric);
            }
        }
        if (!$dataPresent) {
            $minValue = 0.0;
            $maxValue = 100.0;
        }
        if ($minValue === $maxValue) {
            $maxValue = $minValue + 10.0;
            $minValue = max(0.0, $minValue - 10.0);
        }
        $rangePadding = max(5.0, ($maxValue - $minValue) * 0.1);
        $minValue = max(0.0, $minValue - $rangePadding);
        $maxValue = min(120.0, $maxValue + $rangePadding);
        if ($maxValue <= $minValue) {
            $maxValue = $minValue + 10.0;
        }

        $steps = 5;
        for ($i = 0; $i <= $steps; $i++) {
            $ratio = $i / $steps;
            $y = (int) round($plotBottom - ($plotBottom - $plotTop) * $ratio);
            imageline($img, $plotLeft, $y, $plotRight, $y, $gridColor);
            $value = $minValue + ($maxValue - $minValue) * $ratio;
            $label = $this->formatNumber($value);
            $labelWidth = imagefontwidth(2) * strlen($label);
            imagestring($img, 2, max(0, (int) ($plotLeft - $labelWidth - 8)), $y - 7, $label, $labelColor);
        }

        // Draw axes.
        imageline($img, $plotLeft, $plotTop, $plotLeft, $plotBottom, $axisColor);
        imageline($img, $plotLeft, $plotBottom, $plotRight, $plotBottom, $axisColor);

        // Axis titles.
        if ($this->title !== '') {
            $titleWidth = imagefontwidth(4) * strlen($this->title);
            $titleX = (int) (($this->width - $titleWidth) / 2);
            $titleColor = $this->allocateColor($img, $this->paletteColor('title', $this->titleColor));
            imagestring($img, 4, max(0, $titleX), max(6, $plotTop - 32), $this->title, $titleColor);
        }

        $yTitle = $this->yaxis->getTitle();
        if ($yTitle !== '') {
            imagestring($img, 3, max(4, $plotLeft - 60), max(8, $plotTop - 20), $yTitle, $labelColor);
        }

        $xTitle = $this->xaxis->getTitle();
        if ($xTitle !== '') {
            $titleWidth = imagefontwidth(3) * strlen($xTitle);
            $titleX = (int) (($plotLeft + $plotRight - $titleWidth) / 2);
            imagestring($img, 3, max(4, $titleX), $plotBottom + 40, $xTitle, $labelColor);
        }

        // X axis labels.
        $labels = $this->xaxis->getTickLabels();
        $pointCount = count($labels);
        $plotWidth = $plotRight - $plotLeft;
        $xPositions = [];
        if ($pointCount <= 1) {
            $xPositions[] = (int) round(($plotLeft + $plotRight) / 2);
        } else {
            $stepWidth = $plotWidth / max(1, $pointCount - 1);
            for ($i = 0; $i < $pointCount; $i++) {
                $xPositions[$i] = (int) round($plotLeft + $i * $stepWidth);
            }
        }

        $labelIndices = [];
        if ($pointCount <= 6) {
            $labelIndices = range(0, $pointCount - 1);
        } elseif ($pointCount > 0) {
            $step = (int) ceil(($pointCount - 1) / 5);
            for ($i = 0; $i < $pointCount; $i += $step) {
                $labelIndices[] = $i;
            }
            if (end($labelIndices) !== $pointCount - 1) {
                $labelIndices[] = $pointCount - 1;
            }
        }

        foreach ($labelIndices as $idx) {
            if (!isset($xPositions[$idx])) {
                continue;
            }
            $x = $xPositions[$idx];
            imageline($img, $x, $plotBottom, $x, $plotBottom + 6, $axisColor);
            $parts = array_map('trim', explode('·', (string) $labels[$idx]));
            $textY = $plotBottom + 10;
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                $textWidth = imagefontwidth(2) * strlen($part);
                $textX = (int) ($x - $textWidth / 2);
                imagestring($img, 2, max(0, $textX), $textY, $part, $labelColor);
                $textY += 12;
            }
        }

        // Draw data plots.
        foreach ($this->plots as $plot) {
            $data = $plot->getData();
            $plotColor = $plot->getColor() ?? $this->getAxisColor();
            $color = $this->allocateColor($img, $plotColor);
            imagesetthickness($img, $plot->getWeight());
            $prevX = null;
            $prevY = null;
            foreach ($data as $i => $value) {
                if (!isset($xPositions[$i])) {
                    continue;
                }
                if ($value === null || $value === '') {
                    $prevX = null;
                    $prevY = null;
                    continue;
                }
                $numeric = (float) $value;
                $numeric = max($minValue, min($maxValue, $numeric));
                $ratio = ($numeric - $minValue) / ($maxValue - $minValue);
                $y = (int) round($plotBottom - ($plotBottom - $plotTop) * $ratio);
                $x = $xPositions[$i];
                if ($prevX !== null && $prevY !== null) {
                    imageline($img, $prevX, $prevY, $x, $y, $color);
                }
                imagefilledellipse($img, $x, $y, 8, 8, $color);
                $valueLabel = $this->formatNumber($numeric);
                $labelWidth = imagefontwidth(2) * strlen($valueLabel);
                imagestring($img, 2, max(0, $x - $labelWidth / 2), max($plotTop + 4, $y - 16), $valueLabel, $labelColor);
                $prevX = $x;
                $prevY = $y;
            }
        }

        if ($filename) {
            imagepng($img, $filename);
        } else {
            imagepng($img);
        }
        imagedestroy($img);
    }

    public function applyTheme(array $palette): void
    {
        foreach ($palette as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $this->palette[$key] = $value;
            }
        }
        if (isset($palette['margin'])) {
            $this->marginColor = $palette['margin'];
        }
        if (isset($palette['plot'])) {
            $this->plotBackground = $palette['plot'];
        }
        if (isset($palette['grid'])) {
            $this->gridColor = $palette['grid'];
        }
        if (isset($palette['labels'])) {
            $this->labelColor = $palette['labels'];
        }
        if (isset($palette['axis'])) {
            $this->axisColor = $palette['axis'];
            $this->xaxis->SetColor($palette['axis']);
            $this->yaxis->SetColor($palette['axis']);
        }
        if (isset($palette['title'])) {
            $this->titleColor = $palette['title'];
        }
    }

    public function getAxisColor(): string
    {
        if ($this->axisColor !== null && trim($this->axisColor) !== '') {
            return $this->axisColor;
        }
        return $this->paletteColor('axis', $this->marginColor);
    }

    private function paletteColor(string $key, ?string $override = null): string
    {
        $candidate = $override;
        if ($candidate === null || trim($candidate) === '') {
            $candidate = $this->palette[$key] ?? null;
        }
        if (($candidate === null || trim($candidate) === '') && $key !== 'margin') {
            $candidate = $this->palette['margin'] ?? null;
        }
        if ($candidate === null || trim($candidate) === '') {
            throw new RuntimeException(sprintf('Missing chart palette value for "%s"', $key));
        }
        return $candidate;
    }

    private function allocateColor($img, string $color)
    {
        [$r, $g, $b] = $this->parseColor($color);
        return imagecolorallocate($img, $r, $g, $b);
    }

    private function parseColor(string $color): array
    {
        $trimmed = trim($color);
        if ($trimmed === '') {
            throw new RuntimeException('Color value cannot be empty.');
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $trimmed, $matches)) {
            $hex = ltrim($matches[0], '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            $int = hexdec($hex);
            return [($int >> 16) & 0xff, ($int >> 8) & 0xff, $int & 0xff];
        }
        if (preg_match('/^rgba?\(([^\)]+)\)$/i', $trimmed, $matches)) {
            $parts = array_map('trim', explode(',', $matches[1]));
            $r = (int)round((float)($parts[0] ?? 0));
            $g = (int)round((float)($parts[1] ?? 0));
            $b = (int)round((float)($parts[2] ?? 0));
            return [max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b))];
        }
        throw new RuntimeException('Unsupported color format: ' . $color);
    }

    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.01) {
            return (string) round($value);
        }
        return number_format($value, 1);
    }
}
