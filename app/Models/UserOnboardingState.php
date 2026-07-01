<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOnboardingState extends Model
{
    use HasFactory;

    public const PROFILE_COMPLETION_TRT = 'profile_completion_trt';
    public const INSPECTION_QUICK_TOUR_TRT = 'inspection_quick_tour_trt';

    public const CURRENT_VERSIONS = [
        self::PROFILE_COMPLETION_TRT => 'v1',
        self::INSPECTION_QUICK_TOUR_TRT => 'v1',
    ];

    protected $fillable = [
        'user_id',
        'key',
        'version',
        'completed_at',
        'dismissed_at',
        'snoozed_until',
        'last_started_at',
        'payload',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'last_started_at' => 'datetime',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function allowedKeys(): array
    {
        return array_keys(self::CURRENT_VERSIONS);
    }

    public static function currentVersionFor(string $key): ?string
    {
        return self::CURRENT_VERSIONS[$key] ?? null;
    }

    public static function payloadForUser(User $user): array
    {
        $states = self::query()
            ->where('user_id', $user->id)
            ->where(function ($query) {
                foreach (self::CURRENT_VERSIONS as $key => $version) {
                    $query->orWhere(fn ($inner) => $inner->where('key', $key)->where('version', $version));
                }
            })
            ->get();

        return $states
            ->mapWithKeys(fn (self $state) => [$state->key => $state->toPayload()])
            ->all();
    }

    public function toPayload(): array
    {
        return [
            'version' => $this->version,
            'completedAt' => $this->completed_at?->toJSON(),
            'dismissedAt' => $this->dismissed_at?->toJSON(),
            'snoozedUntil' => $this->snoozed_until?->toJSON(),
            'lastStartedAt' => $this->last_started_at?->toJSON(),
            'payload' => $this->payload,
        ];
    }
}
