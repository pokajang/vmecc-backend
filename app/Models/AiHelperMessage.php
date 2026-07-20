<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiHelperMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_STREAMING = 'streaming';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABORTED = 'aborted';

    protected $fillable = [
        'thread_id',
        'role',
        'content',
        'route_context',
        'openai_response_id',
        'status',
        'error',
        'sources',
        'retrieval_metadata',
    ];

    protected $casts = [
        'route_context' => 'array',
        'sources' => 'array',
        'retrieval_metadata' => 'array',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiHelperThread::class, 'thread_id');
    }

    public function responseReports(): HasMany
    {
        return $this->hasMany(AiHelperResponseReport::class, 'assistant_message_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiHelperRun::class, 'assistant_message_id');
    }
}
