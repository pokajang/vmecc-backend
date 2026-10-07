<?php

namespace App\Support;

final class PdfDocumentOptions
{
    /** @return array<string, bool|string> */
    public static function secure(): array
    {
        return [
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ];
    }
}
