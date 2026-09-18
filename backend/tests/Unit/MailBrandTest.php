<?php

namespace Tests\Unit;

use App\Mail\MailBrand;
use Tests\TestCase;

class MailBrandTest extends TestCase
{
    public function test_flow_logo_is_embedded_png(): void
    {
        $src = MailBrand::flowLogoSrc();

        $this->assertNotSame('', $src);
        $this->assertStringStartsWith('data:image/png;base64,', $src);
    }

    public function test_uniform_font_face_is_embedded(): void
    {
        $css = MailBrand::fontFaceCss();

        $this->assertStringContainsString("font-family:Uniform", $css);
        $this->assertStringContainsString('font/otf;base64,', $css);
    }
}
