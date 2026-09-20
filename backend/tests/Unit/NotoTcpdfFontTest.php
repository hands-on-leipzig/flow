<?php

namespace Tests\Unit;

use App\Print\NotoTcpdfFont;
use PHPUnit\Framework\TestCase;

class NotoTcpdfFontTest extends TestCase
{
    public function test_regular_family_is_non_empty(): void
    {
        $this->assertNotSame('', NotoTcpdfFont::regular());
    }
}
