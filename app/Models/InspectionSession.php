<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InspectionSession extends Model
{
    protected $fillable = [
        'session_uid',
        'inspection_type',
        'inspection_type_key',
        'status',
        'scope_version',
        'scope_key',
        'scope_zone',
        'scope_main_location',
        'scope',
        'started_by_user_id',
        'submitted_by_user_id',
        'submitted_report_uid',
        'submitted_at',
        'version',
    ];

    protected $casts = [
        'scope' => 'array',
        'submitted_at' => 'datetime',
        'version' => 'integer',
    ];

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function extinguisherResults(): HasMany
    {
        return $this->hasMany(InspectionExtinguisherResult::class);
    }

    public function extinguisherOperations(): HasMany
    {
        return $this->hasMany(InspectionExtinguisherOperation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(InspectionSessionEvent::class);
    }

    public function locationProgress(): HasMany
    {
        return $this->hasMany(InspectionSessionLocationProgress::class);
    }

    public function scopeClaim(): HasOne
    {
        return $this->hasOne(InspectionSessionScopeClaim::class);
    }
}
