<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionFireExtinguisherIssueOccurrence extends Model
{
    protected $fillable = [
        'issue_id', 'inspection_check_row_id', 'report_id', 'check_value', 'remarks',
        'evidence_count', 'detected_at',
    ];

    protected $casts = ['detected_at' => 'datetime', 'evidence_count' => 'integer'];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(InspectionFireExtinguisherIssue::class, 'issue_id');
    }
}
