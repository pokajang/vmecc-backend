<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

class DutyCoverageAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'acting_team_id',
        'home_team_id',
        'acting_role_id',
        'replaces_user_id',
        'roster_id',
        'shift_key',
        'effective_from',
        'effective_until',
        'reason',
        'approved_by_user_id',
        'created_by_user_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function scopeEffectiveAt(Builder $query, Carbon $at): Builder
    {
        return $query
            ->whereNull('cancelled_at')
            ->where('effective_from', '<=', $at)
            ->where('effective_until', '>', $at);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actingTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'acting_team_id');
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function actingRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'acting_role_id');
    }

    public function replacesUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaces_user_id');
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(Roster::class);
    }
}
