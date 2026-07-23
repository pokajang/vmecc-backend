<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FitnessTestReport extends Model
{
    protected $fillable = [
        'report_id',
        'reporting_month',
        'document_reference',
        'protocol_revision',
        'participant_count',
        'passed_assessment_count',
        'failed_assessment_count',
        'incomplete_assessment_count',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function shiftGroups(): HasMany
    {
        return $this->hasMany(FitnessTestShiftGroup::class)->orderBy('display_order')->orderBy('id');
    }
}
