<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FitnessTestShiftGroup extends Model
{
    protected $fillable = [
        'fitness_test_report_id',
        'source_group_uid',
        'team_id',
        'shift_name_snapshot',
        'assessor_user_id',
        'assessor_name_snapshot',
        'display_order',
    ];

    public function fitnessTestReport(): BelongsTo
    {
        return $this->belongsTo(FitnessTestReport::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(FitnessTestParticipantResult::class)->orderBy('display_order')->orderBy('id');
    }
}
