<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionSessionScopeClaim extends Model
{
    protected $fillable = ['scope_key', 'inspection_session_id'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(InspectionSession::class, 'inspection_session_id');
    }
}
