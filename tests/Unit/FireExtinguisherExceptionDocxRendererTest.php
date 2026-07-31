<?php

namespace Tests\Unit;

use App\Services\InspectionFireExtinguishers\FireExtinguisherExceptionDocxRenderer;
use DOMDocument;
use Tests\TestCase;
use ZipArchive;

class FireExtinguisherExceptionDocxRendererTest extends TestCase
{
    public function test_dynamic_text_is_xml_escaped_in_the_generated_document(): void
    {
        $output = app(FireExtinguisherExceptionDocxRenderer::class)->render([
            'title' => 'Fire Extinguisher Expired Certification Report',
            'generatedAtDisplay' => '31 Jul 2026, 08:13',
            'generatedBy' => 'Test User',
            'asOfDateDisplay' => '31 Jul 2026',
            'layoutMode' => 'expired',
            'summary' => [
                'total' => 1,
                'issues' => 0,
                'expired' => 1,
                'overlap' => 0,
            ],
            'items' => [[
                'zone' => '4 & 4B',
                'location' => 'Test Location',
                'idLocNo' => 'FE-100',
                'subLocation' => 'Test Sub-location',
                'feType' => 'DP 9KG',
                'barcodeNo' => 'TEST-100',
                'certificationValidity' => '2026-07-01',
                'daysExpired' => 30,
                'latestInspectionAt' => null,
                'inspectedBy' => null,
                'isIssue' => false,
                'isExpired' => true,
                'defects' => [],
            ]],
        ]);

        $temporary = tempnam(sys_get_temp_dir(), 'fe-docx-unit-');
        $this->assertNotFalse($temporary);

        try {
            file_put_contents($temporary, $output);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($temporary) === true);
            $documentXml = $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertIsString($documentXml);
            $this->assertStringContainsString('4 &amp; 4B', $documentXml);

            $previousLibxmlSetting = libxml_use_internal_errors(true);
            $document = new DOMDocument;
            $documentIsValid = $document->loadXML($documentXml, LIBXML_NONET);
            $xmlErrors = array_map(
                static fn ($error): string => trim($error->message),
                libxml_get_errors(),
            );
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlSetting);

            $this->assertTrue(
                $documentIsValid,
                'DOCX document.xml is malformed: '.implode('; ', $xmlErrors),
            );
        } finally {
            @unlink($temporary);
        }
    }
}
