<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHelperResponseReport extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_REVIEWING,
        self::STATUS_RESOLVED,
        self::STATUS_DISMISSED,
    ];

    protected $fillable = [
        'reporter_user_id',
        'thread_id',
        'assistant_message_id',
        'preceding_user_message_id',
        'reason',
        'status',
        'assistant_content',
        'preceding_user_content',
        'page_context',
        'chat_snapshot',
        'openai_response_id',
        'reporter_ip',
        'reporter_user_agent',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'page_context' => 'array',
        'chat_snapshot' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiHelperThread::class, 'thread_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(AiHelperMessage::class, 'assistant_message_id');
    }

    public function precedingUserMessage(): BelongsTo
    {
        return $this->belongsTo(AiHelperMessage::class, 'preceding_user_message_id');
    }
}
