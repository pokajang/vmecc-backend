<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FitnessTestCheckpointResult extends Model
{
    protected $fillable = [
        'fitness_test_participant_result_id',
        'checkpoint_code',
        'completed',
        'duration_seconds',
        'attempts',
        'remarks',
        'display_order',
    ];

    protected $casts = [
        'completed' => 'boolean',
    ];

    public function participantResult(): BelongsTo
    {
        return $this->belongsTo(FitnessTestParticipantResult::class, 'fitness_test_participant_result_id');
    }
}
