<?php

declare(strict_types=1);

class SimplePdfDocument
{
    private float $width = 595.28; // A4 width in points
    private float $height = 841.89; // A4 height in points
    private float $marginLeft = 50.0;
    private float $marginRight = 50.0;
    private float $marginTop = 50.0;
    private float $marginBottom = 60.0;
    private float $cursorY;
    private array $pages = [];
    private array $currentOps = [];
    private bool $pageOpen = false;

    public function __construct()
    {
        $this->startNewPage();
    }

    public function addHeading(string $text): void
    {
        $this->addTextBlock($text, 18.0, 'F2', 1.5);
        $this->addSpacer(6.0);
    }

    public function addSubheading(string $text): void
    {
        $this->addTextBlock($text, 14.0, 'F2', 1.4);
        $this->addSpacer(4.0);
    }

    public function addParagraph(string $text, float $fontSize = 11.0): void
    {
        $this->addTextBlock($text, $fontSize, 'F1', 1.35);
        $this->addSpacer(4.0);
    }

    public function addBulletList(array $lines, float $fontSize = 11.0): void
    {
        foreach ($lines as $line) {
            $this->addTextBlock('• ' . $line, $fontSize, 'F1', 1.35, false);
        }
        $this->addSpacer(4.0);
    }

    public function addSpacer(float $points): void
    {
        $this->cursorY -= $points;
        if ($this->cursorY <= $this->marginBottom) {
            $this->startNewPage();
        }
    }

    public function addTable(array $headers, array $rows, array $columnWidths, float $fontSize = 10.0): void
    {
        $this->ensurePage();
        $lineHeight = $this->lineHeight($fontSize, 1.35);
        $formattedHeader = $this->formatTableRow($headers, $columnWidths);
        $this->writeMonospaceLine($formattedHeader, $fontSize);
        $this->cursorY -= $lineHeight;
        $this->ensureSpace($lineHeight);
        $separator = $this->formatTableSeparator($columnWidths);
        $this->writeMonospaceLine($separator, $fontSize);
        $this->cursorY -= $lineHeight;
        foreach ($rows as $row) {
            $this->ensureSpace($lineHeight);
            $this->writeMonospaceLine($this->formatTableRow($row, $columnWidths), $fontSize);
            $this->cursorY -= $lineHeight;
        }
        $this->addSpacer(6.0);
    }

    public function output(): string
    {
        if ($this->pageOpen) {
            $this->pages[] = $this->currentOps;
            $this->currentOps = [];
            $this->pageOpen = false;
        }

        if (!$this->pages) {
            $this->startNewPage();
            $this->pages[] = $this->currentOps;
            $this->currentOps = [];
            $this->pageOpen = false;
        }

        $objects = [];
        $pageReferences = [];
        $objectIndex = 6; // reserve 1-5 for catalog, pages, fonts

        foreach ($this->pages as $ops) {
            $content = implode("\n", $ops) . "\n";
            $contentObjNum = $objectIndex++;
            $objects[$contentObjNum] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $pageObjNum = $objectIndex++;
            $pageReferences[] = $pageObjNum;
            $pageObject = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->formatFloat($this->width) . ' ' . $this->formatFloat($this->height) . '] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> '
                . '/Contents ' . $contentObjNum . ' 0 R >>';
            $objects[$pageObjNum] = $pageObject;
        }

        $pagesObject = '<< /Type /Pages /Kids [';
        foreach ($pageReferences as $ref) {
            $pagesObject .= ' ' . $ref . ' 0 R';
        }
        $pagesObject .= ' ] /Count ' . count($pageReferences) . ' >>';

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = $pagesObject;
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

        ksort($objects);

        $output = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $num => $content) {
            $offsets[$num] = strlen($output);
            $output .= $num . " 0 obj\n" . $content . "\nendobj\n";
        }

        $xrefPosition = strlen($output);
        $maxObject = max(array_keys($objects));
        $output .= 'xref\n0 ' . ($maxObject + 1) . "\n";
        $output .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObject; $i++) {
            $offset = $offsets[$i] ?? 0;
            $output .= sprintf("%010d 00000 n \n", $offset);
        }

        $output .= 'trailer << /Size ' . ($maxObject + 1) . ' /Root 1 0 R >>' . "\n";
        $output .= 'startxref' . "\n" . $xrefPosition . "\n%%EOF";

        return $output;
    }

    private function addTextBlock(string $text, float $fontSize, string $fontKey, float $lineSpacing, bool $addSpacer = true): void
    {
        $this->ensurePage();
        $lines = $this->wrapText($text, $fontSize);
        $lineHeight = $this->lineHeight($fontSize, $lineSpacing);
        foreach ($lines as $line) {
            $this->ensureSpace($lineHeight);
            $this->writeTextLine($line, $fontKey, $fontSize);
            $this->cursorY -= $lineHeight;
        }
        if ($addSpacer) {
            $this->cursorY -= 2.0;
        }
    }

    private function ensurePage(): void
    {
        if (!$this->pageOpen) {
            $this->startNewPage();
        }
    }

    private function startNewPage(): void
    {
        if ($this->pageOpen) {
            $this->pages[] = $this->currentOps;
        }
        $this->currentOps = [];
        $this->cursorY = $this->height - $this->marginTop;
        $this->pageOpen = true;
    }

    private function ensureSpace(float $lineHeight): void
    {
        if ($this->cursorY - $lineHeight <= $this->marginBottom) {
            $this->startNewPage();
        }
    }

    private function writeTextLine(string $text, string $fontKey, float $fontSize): void
    {
        $escaped = $this->escapeText($text);
        $x = $this->marginLeft;
        $y = $this->cursorY;
        $this->currentOps[] = sprintf('BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET', $fontKey, $fontSize, $x, $y, $escaped);
    }

    private function writeMonospaceLine(string $text, float $fontSize): void
    {
        $escaped = $this->escapeText($text);
        $x = $this->marginLeft;
        $y = $this->cursorY;
        $this->currentOps[] = sprintf('BT /F3 %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET', $fontSize, $x, $y, $escaped);
    }

    private function wrapText(string $text, float $fontSize): array
    {
        $normalized = preg_replace("/\s+/u", ' ', trim($text));
        if ($normalized === '') {
            return [''];
        }
        $availableWidth = $this->width - $this->marginLeft - $this->marginRight;
        $avgCharWidth = max(0.1, $fontSize * 0.55);
        $maxChars = max(10, (int)floor($availableWidth / $avgCharWidth));
        $wrapped = wordwrap($normalized, $maxChars, "\n", true);
        return explode("\n", $wrapped);
    }

    private function lineHeight(float $fontSize, float $multiplier): float
    {
        return $fontSize * $multiplier;
    }

    private function escapeText(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        return preg_replace("/[\r\n]+/", ' ', $text);
    }

    private function formatTableRow(array $row, array $columnWidths): string
    {
        $cells = [];
        $count = min(count($row), count($columnWidths));
        for ($i = 0; $i < $count; $i++) {
            $cell = (string)$row[$i];
            $width = max(1, (int)$columnWidths[$i]);
            $cell = mb_strimwidth($cell, 0, $width, '', 'UTF-8');
            $cells[] = str_pad($cell, $width);
        }
        return implode('  ', $cells);
    }

    private function formatTableSeparator(array $columnWidths): string
    {
        $parts = [];
        foreach ($columnWidths as $width) {
            $parts[] = str_repeat('-', max(1, (int)$width));
        }
        return implode('  ', $parts);
    }

    private function formatFloat(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
