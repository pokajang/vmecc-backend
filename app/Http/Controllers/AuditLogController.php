<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 200);
        if ($limit <= 0) {
            $limit = 200;
        }
        $limit = min($limit, 500);

        $query = AuditLog::query()->with('actor')->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }
        if ($request->filled('actor_id')) {
            $query->where('actor_user_id', $request->query('actor_id'));
        }
        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->query('subject_id'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to'));
        }

        $logs = $query->limit($limit)->get()->map(function (AuditLog $log) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor
                    ? [
                        'id' => $log->actor->id,
                        'name' => $log->actor->name,
                        'email' => $log->actor->email,
                    ]
                    : null,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'metadata' => $log->metadata,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => optional($log->created_at)->toIso8601String(),
            ];
        });

        return response()->json(['data' => $logs]);
    }
}
