<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class DownloadFireExtinguisherExceptionExportRequest extends FireExtinguisherExceptionExportRequest
{
    /** @return array<int, mixed> */
    protected function formatRules(): array
    {
        return ['required', 'string', Rule::in(['pdf', 'docx'])];
    }
}
