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
        } finally {
            @unlink($tmp);
        }
    }
}
