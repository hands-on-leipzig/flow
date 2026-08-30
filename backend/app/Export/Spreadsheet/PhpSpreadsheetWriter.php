<?php

namespace App\Export\Spreadsheet;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class PhpSpreadsheetWriter implements SpreadsheetWriter
{
    /** @var array<string, true> */
    private array $usedTableNames = [];

    public function write(SpreadsheetDocument $document): string
    {
        $this->usedTableNames = [];
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($document->sheets as $index => $sheetSpec) {
            $worksheet = $spreadsheet->createSheet($index);
            $title = $this->safeSheetTitle($sheetSpec->title);
            $worksheet->setTitle($title);

            $colCount = count($sheetSpec->columns);
            foreach ($sheetSpec->columns as $colIndex => $column) {
                $cell = $worksheet->getCell([$colIndex + 1, 1]);
                $cell->setValueExplicit($column->label, DataType::TYPE_STRING);
            }

            if ($colCount > 0) {
                $worksheet->getStyle([1, 1, $colCount, 1])->getFont()->setBold(true);
            }

            $rowNumber = 2;
            foreach ($sheetSpec->rows as $row) {
                foreach ($sheetSpec->columns as $colIndex => $column) {
                    $value = $row[$colIndex] ?? null;
                    $this->writeCell($worksheet, $colIndex + 1, $rowNumber, $column, $value);
                }
                $rowNumber++;
            }

            if ($colCount > 0) {
                $lastRow = max(1, $rowNumber - 1);
                $this->applyTable($worksheet, $title, $colCount, $lastRow);
                $this->autoSizeColumns($worksheet, $colCount);
            }
        }

        if ($document->sheets === []) {
            $spreadsheet->createSheet(0)->setTitle('Sheet1');
        }

        $spreadsheet->setActiveSheetIndex(0);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheet->calculateColumnWidths();
        }

        $tmp = tempnam(sys_get_temp_dir(), 'flow-xlsx-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create temporary file for spreadsheet export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($tmp);
            $binary = file_get_contents($tmp);
            if ($binary === false) {
                throw new \RuntimeException('Could not read spreadsheet export.');
            }

            return $binary;
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($tmp);
        }
    }

    private function applyTable(Worksheet $worksheet, string $sheetTitle, int $colCount, int $lastRow): void
    {
        $table = new Table([1, 1, $colCount, $lastRow], $this->uniqueTableName($sheetTitle));
        $style = new TableStyle;
        $style->setTheme(TableStyle::TABLE_STYLE_MEDIUM2);
        $style->setShowRowStripes(true);
        $table->setStyle($style);
        $worksheet->addTable($table);
    }

    private function autoSizeColumns(Worksheet $worksheet, int $colCount): void
    {
        for ($columnIndex = 1; $columnIndex <= $colCount; $columnIndex++) {
            $letter = Coordinate::stringFromColumnIndex($columnIndex);
            $worksheet->getColumnDimension($letter)->setAutoSize(true);
        }
    }

    private function uniqueTableName(string $sheetTitle): string
    {
        $base = preg_replace('/[^\p{L}\p{M}0-9._]/u', '_', $sheetTitle) ?? 'Table';
        $base = preg_replace('/_+/', '_', $base) ?? 'Table';
        $base = trim($base, '_');
        if ($base === '' || ! preg_match('/^[\p{L}_\\\\]/u', $base)) {
            $base = 'Table_'.$base;
        }
        $base = mb_substr($base, 0, 200);

        $candidate = $base;
        $suffix = 2;
        while (isset($this->usedTableNames[mb_strtolower($candidate)])) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        $this->usedTableNames[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    private function writeCell(
        Worksheet $worksheet,
        int $columnIndex,
        int $rowNumber,
        SpreadsheetColumn $column,
        mixed $value,
    ): void {
        $cell = $worksheet->getCell([$columnIndex, $rowNumber]);

        if ($value === null || $value === '') {
            $cell->setValueExplicit('', DataType::TYPE_STRING);

            return;
        }

        match ($column->type) {
            SpreadsheetColumnType::String => $cell->setValueExplicit((string) $value, DataType::TYPE_STRING),
            SpreadsheetColumnType::Number => $cell->setValueExplicit((float) $value, DataType::TYPE_NUMERIC),
            SpreadsheetColumnType::Bool => $cell->setValueExplicit((bool) $value ? '1' : '0', DataType::TYPE_STRING),
            SpreadsheetColumnType::Date => $this->writeDateCell($worksheet, $columnIndex, $rowNumber, $value),
        };
    }

    private function writeDateCell(
        Worksheet $worksheet,
        int $columnIndex,
        int $rowNumber,
        mixed $value,
    ): void {
        $cell = $worksheet->getCell([$columnIndex, $rowNumber]);

        if ($value instanceof DateTimeInterface) {
            $cell->setValue(ExcelDate::PHPToExcel($value));
            $cell->getStyle()->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DATETIME);

            return;
        }

        // Fallback: keep as formula-safe string when not a DateTimeInterface
        $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
    }

    private function safeSheetTitle(string $title): string
    {
        $clean = preg_replace('/[\\\\\/\?\*\[\]:]/', '', $title) ?? $title;
        $clean = trim($clean);
        if ($clean === '') {
            $clean = 'Sheet';
        }

        return mb_substr($clean, 0, 31);
    }
}
