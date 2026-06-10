<?php

namespace App\Http\Controllers;

use App\Services\WorkflowNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowNotificationController extends Controller
{
    public function __construct(private readonly WorkflowNotificationService $notificationService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $unreadOnly = $request->boolean('unread_only');
        $actionRequiredOnly = $request->boolean('action_required_only');
        $module = trim((string) $request->input('module', '')) ?: null;
        $limit = min(max((int) ($request->input('limit') ?? 50), 1), 100);

        $data = $this->notificationService->forViewer(
            $user->id,
            $unreadOnly,
            $actionRequiredOnly,
            $limit,
            $module,
        );

        return response()->json(['data' => $data->values()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $module = trim((string) $request->input('module', '')) ?: null;

        $count = $this->notificationService->unreadCount($user->id, $module);

        return response()->json(['data' => ['count' => $count]]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->notificationService->markRead($id, $user->id);

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $module = trim((string) $request->input('module', '')) ?: null;

        $this->notificationService->markAllRead($user->id, $module);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function dismiss(Request $request, int $id): JsonResponse
    {
        $this->notificationService->dismiss($id, $request->user()->id);

        return response()->json(['message' => 'Notification dismissed.']);
    }

    public function dismissAll(Request $request): JsonResponse
    {
        $this->notificationService->dismissAll($request->user()->id);

        return response()->json(['message' => 'All notifications dismissed.']);
    }
}
