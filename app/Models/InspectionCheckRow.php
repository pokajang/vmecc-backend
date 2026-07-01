<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InspectionCheckRow extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'report_id',
        'report_uid',
        'display_id',
        'owner_user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'submitted_by_user_id',
        'inspection_type',
        'inspection_type_key',
        'location',
        'main_location',
        'sub_location',
        'equipment',
        'equipment_key',
        'equipment_catalog_id',
        'equipment_source',
        'check_group',
        'check_key',
        'check_name',
        'check_value',
        'remarks',
        'has_defect',
        'has_evidence',
        'evidence_count',
        'report_status',
        'report_version',
        'report_revision',
        'submitted_at',
        'source_payload_key',
        'source_row_id',
        'sort_order',
    ];

    protected $casts = [
        'has_defect' => 'boolean',
        'has_evidence' => 'boolean',
        'submitted_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
