<?php

namespace App\Export\Spreadsheet;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class PhpSpreadsheetWriter implements SpreadsheetWriter
{
    public function write(SpreadsheetDocument $document): string
    {
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
        }

        if ($document->sheets === []) {
            $spreadsheet->createSheet(0)->setTitle('Sheet1');
        }

        $spreadsheet->setActiveSheetIndex(0);

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

    private function writeCell(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet,
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
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet,
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
