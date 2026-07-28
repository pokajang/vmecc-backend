<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OvertimeRecord extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'display_id',
        'overtime_type',
        'claim_date',
        'start_time',
        'end_time',
        'is_overnight',
        'duration_minutes',
        'reason',
        'status',
        'applied_at',
        'workflow_stage',
        'workflow_snapshot',
        'workflow_team_id',
        'workflow_team_name',
        'workflow_applicant_role',
        'workflow_routing_source',
        'duty_coverage_assignment_id',
        'next_action_role',
        'applicant_roles',
        'approval_history',
        'submitted_by',
        'attachment_id',
        'version',
    ];

    protected $casts = [
        'claim_date' => 'date',
        'is_overnight' => 'boolean',
        'duration_minutes' => 'integer',
        'version' => 'integer',
        'applied_at' => 'datetime',
        'workflow_snapshot' => 'array',
        'applicant_roles' => 'array',
        'approval_history' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(WorkflowAttachment::class, 'attachment_id');
    }

    public function workflowTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'workflow_team_id');
    }

    public function dutyCoverageAssignment(): BelongsTo
    {
        return $this->belongsTo(DutyCoverageAssignment::class);
    }
}
