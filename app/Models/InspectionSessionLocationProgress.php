<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionSessionLocationProgress extends Model
{
    protected $table = 'inspection_session_location_progress';

    protected $fillable = [
        'inspection_session_id',
        'zone',
        'main_location',
        'sub_location',
        'status',
        'expected_count',
        'completed_count',
        'completed_by_user_id',
        'completed_at',
        'version',
    ];

    protected $casts = [
        'expected_count' => 'integer',
        'completed_count' => 'integer',
        'completed_at' => 'datetime',
        'version' => 'integer',
    ];

    public function inspectionSession(): BelongsTo
    {
        return $this->belongsTo(InspectionSession::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
