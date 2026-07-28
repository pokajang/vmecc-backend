<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class TeamRoleTransfer extends Model
{
    protected $fillable = [
        'user_id',
        'role_id',
        'from_team_id',
        'to_team_id',
        'from_assignment_id',
        'to_assignment_id',
        'handover_user_id',
        'transferred_by_user_id',
        'effective_date',
        'pending_handover_count',
        'reason',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'pending_handover_count' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function fromTeam()
    {
        return $this->belongsTo(Team::class, 'from_team_id');
    }

    public function toTeam()
    {
        return $this->belongsTo(Team::class, 'to_team_id');
    }

    public function routingEvents()
    {
        return $this->hasMany(ReportRoutingEvent::class);
    }
}
