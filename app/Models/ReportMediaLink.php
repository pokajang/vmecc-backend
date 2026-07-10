<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportMediaLink extends Model
{
    protected $fillable = ['report_media_id', 'parent_type', 'parent_key'];

    public function media(): BelongsTo
    {
        return $this->belongsTo(ReportMedia::class, 'report_media_id');
    }
}
