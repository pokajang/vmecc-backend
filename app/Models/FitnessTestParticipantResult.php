<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FitnessTestParticipantResult extends Model
{
    protected $fillable = [
        'fitness_test_shift_group_id',
        'source_participant_uid',
        'user_id',
        'participant_name_snapshot',
        'role_snapshot',
        'age_snapshot',
        'source',
        'display_order',
        'fitness_tested_on',
        'sit_ups',
        'jumping_jacks',
        'push_ups',
        'fitness_result',
        'fitness_result_source',
        'proficiency_tested_on',
        'proficiency_duration_seconds',
        'proficiency_result',
        'proficiency_result_source',
    ];

    protected $casts = [
        'fitness_tested_on' => 'datetime',
        'proficiency_tested_on' => 'datetime',
    ];

    public function shiftGroup(): BelongsTo
    {
        return $this->belongsTo(FitnessTestShiftGroup::class, 'fitness_test_shift_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkpointResults(): HasMany
    {
        return $this->hasMany(FitnessTestCheckpointResult::class)->orderBy('display_order')->orderBy('id');
    }
}
