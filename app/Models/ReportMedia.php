<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportMedia extends Model
{
    protected $table = 'report_media';

    protected $fillable = [
        'public_id', 'client_upload_id', 'user_id', 'module', 'disk', 'storage_path', 'thumbnail_path',
        'original_name', 'mime_type', 'size_bytes', 'thumbnail_size_bytes', 'width', 'thumbnail_width',
        'height', 'thumbnail_height', 'checksum_sha256', 'thumbnail_checksum_sha256',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'thumbnail_size_bytes' => 'integer',
        'width' => 'integer',
        'thumbnail_width' => 'integer',
        'height' => 'integer',
        'thumbnail_height' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(ReportMediaLink::class);
    }
}
