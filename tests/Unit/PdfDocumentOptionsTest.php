<?php

namespace Tests\Unit;

use App\Support\PdfDocumentOptions;
use PHPUnit\Framework\TestCase;

class PdfDocumentOptionsTest extends TestCase
{
    public function test_secure_options_disable_active_and_remote_content_and_enable_unicode_font_support(): void
    {
        $options = PdfDocumentOptions::secure();

        $this->assertSame('DejaVu Sans', $options['defaultFont']);
        $this->assertTrue($options['isFontSubsettingEnabled']);
        $this->assertTrue($options['isHtml5ParserEnabled']);
        $this->assertFalse($options['isRemoteEnabled']);
        $this->assertFalse($options['isPhpEnabled']);
        $this->assertFalse($options['isJavascriptEnabled']);
    }
}
