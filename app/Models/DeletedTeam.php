<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeletedTeam extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'status',
        'image_url',
        'lead_id',
        'lead_name',
        'members_snapshot',
        'deleted_by_user_id',
        'deleted_at',
    ];

    protected $casts = [
        'members_snapshot' => 'array',
        'deleted_at'       => 'datetime',
    ];

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }
}
