<?php

namespace Tests\Unit\Export;

use App\Export\Spreadsheet\PhpSpreadsheetWriter;
use App\Export\Spreadsheet\SpreadsheetColumn;
use App\Export\Spreadsheet\SpreadsheetColumnType;
use App\Export\Spreadsheet\SpreadsheetDocument;
use App\Export\Spreadsheet\SpreadsheetSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhpSpreadsheetWriterTest extends TestCase
{
    #[Test]
    public function it_writes_multi_sheet_xlsx_with_string_typed_cells(): void
    {
        $document = new SpreadsheetDocument(
            'Fixture',
            '25.08.26',
            [
                new SpreadsheetSheet(
                    'People',
                    [
                        new SpreadsheetColumn('name', 'Name', SpreadsheetColumnType::String),
                        new SpreadsheetColumn('count', 'Count', SpreadsheetColumnType::Number),
                    ],
                    [
                        ['=1+1', 3],
                        ['Ada', 7],
                    ],
                ),
                new SpreadsheetSheet(
                    'Meta',
                    [
                        new SpreadsheetColumn('key', 'Key', SpreadsheetColumnType::String),
                    ],
                    [
                        ['ok'],
                    ],
                ),
            ],
        );

        $binary = (new PhpSpreadsheetWriter)->write($document);

        $this->assertNotSame('', $binary);
        $this->assertSame('PK', substr($binary, 0, 2));

        $tmp = tempnam(sys_get_temp_dir(), 'flow-xlsx-test-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $binary);

        try {
            $loaded = IOFactory::load($tmp);
            $this->assertSame(2, $loaded->getSheetCount());

            $people = $loaded->getSheet(0);
            $this->assertSame('People', $people->getTitle());
            $this->assertSame('Name', $people->getCell('A1')->getValue());
            $this->assertSame('Count', $people->getCell('B1')->getValue());
            $this->assertTrue($people->getStyle('A1')->getFont()->getBold());

            $formulaLike = $people->getCell('A2');
            $this->assertSame('=1+1', $formulaLike->getValue());
            $this->assertSame(DataType::TYPE_STRING, $formulaLike->getDataType());

            $this->assertSame(3.0, (float) $people->getCell('B2')->getValue());
            $this->assertSame('Ada', $people->getCell('A3')->getValue());

            $meta = $loaded->getSheet(1);
            $this->assertSame('Meta', $meta->getTitle());
            $this->assertSame('ok', $meta->getCell('A2')->getValue());

            $this->assertCount(1, $people->getTableCollection());
            $peopleTable = $people->getTableCollection()[0];
            $this->assertSame('People', $peopleTable->getName());
            $this->assertSame('A1:B3', $peopleTable->getRange());
            $this->assertGreaterThan(0, $people->getColumnDimension('A')->getWidth());
            $this->assertGreaterThan(0, $people->getColumnDimension('B')->getWidth());

            $this->assertCount(1, $meta->getTableCollection());
            $this->assertGreaterThan(0, $meta->getColumnDimension('A')->getWidth());
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function it_writes_datetimes_as_excel_serial_values(): void
    {
        $when = \Carbon\Carbon::parse('2026-08-30 15:45:00', 'UTC');

        $document = new SpreadsheetDocument(
            'Dates',
            '30.08.26',
            [
                new SpreadsheetSheet(
                    'Dates',
                    [
                        new SpreadsheetColumn('changed', 'Letzte Änderung', SpreadsheetColumnType::Date),
                    ],
                    [
                        [$when],
                        ['2026-08-30T17:45:00+02:00'],
                    ],
                ),
            ],
        );

        $binary = (new PhpSpreadsheetWriter)->write($document);
        $tmp = tempnam(sys_get_temp_dir(), 'flow-xlsx-date-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $binary);

        try {
            $sheet = IOFactory::load($tmp)->getActiveSheet();
            $cell = $sheet->getCell('A2');
            $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
            $this->assertSame('DD.MM.YYYY HH:MM', $cell->getStyle()->getNumberFormat()->getFormatCode());
            $this->assertIsFloat($cell->getValue());

            $parsed = $sheet->getCell('A3');
            $this->assertSame(DataType::TYPE_NUMERIC, $parsed->getDataType());
        } finally {
            @unlink($tmp);
        }
    }
}
