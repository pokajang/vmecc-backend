<?php

namespace App\Http\Controllers;

use App\Services\WorkflowNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveNotificationController extends Controller
{
    public function __construct(
        private readonly WorkflowNotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $unread = $request->boolean('unread_only');
        $action = $request->boolean('action_required_only');
        $limit  = min((int) ($request->input('limit') ?? 50), 100);

        $items = $this->notificationService->forViewer($user->id, $unread, $action, $limit, 'leave');

        return response()->json(['data' => $items->values()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user  = $request->user();
        $count = $this->notificationService->unreadCount($user->id, 'leave');

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
        $this->notificationService->markAllRead($user->id, 'leave');

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
