<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportRoutingEvent extends Model
{
    protected $fillable = [
        'report_id',
        'team_role_transfer_id',
        'event_type',
        'from_user_id',
        'to_user_id',
        'team_id',
        'required_role',
        'created_by_user_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
