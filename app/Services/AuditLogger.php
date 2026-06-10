<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function log(Request $request, string $action, ?User $subject = null, array $metadata = []): void
    {
        $actor = $request->user();
        $meta = $metadata;

        if ($subject) {
            $meta['subject'] = [
                'id' => $subject->id,
                'name' => $subject->name,
                'email' => $subject->email,
            ];
        }

        AuditLog::create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? 'user' : null,
            'subject_id' => $subject?->id,
            'metadata' => $meta ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
