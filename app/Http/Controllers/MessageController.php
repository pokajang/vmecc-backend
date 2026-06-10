<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    public function __construct(private readonly AssignmentAuthorizationService $authorizationService)
    {
    }

    public function contacts(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->whereNull('deleted_at')
            ->where('id', '!=', $actor->id);

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
                ];
            });

        return response()->json(['data' => $users]);
    }

    public function threads(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $limit = (int) $request->query('limit', 200);
        if ($limit <= 0) {
            $limit = 200;
        }
        $limit = min($limit, 500);

        // Fetch hidden cutoff timestamps keyed by other_user_id
        $hiddenCutoffs = DB::table('message_thread_hidden')
            ->where('user_id', $actor->id)
            ->pluck('hidden_before', 'other_user_id');

        $messages = Message::with([
            'sender' => fn ($query) => $query->withTrashed(),
            'recipient' => fn ($query) => $query->withTrashed(),
            'attachment',
        ])
            ->where('sender_user_id', $actor->id)
            ->orWhere('recipient_user_id', $actor->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $threads = [];
        foreach ($messages as $message) {
            $other = $message->sender_user_id === $actor->id ? $message->recipient : $message->sender;
            if (! $other) {
                continue;
            }
            // Skip messages older than the hidden cutoff for this thread
            $cutoff = $hiddenCutoffs[$other->id] ?? null;
            if ($cutoff && $message->created_at <= $cutoff) {
                continue;
            }
            if (! isset($threads[$other->id])) {
                $threads[$other->id] = [
                    'user' => [
                        'id' => $other->id,
                        'name' => $other->name,
                        'email' => $other->email,
                        'roles' => $this->authorizationService->getActiveRoleNames($other)->values()->all(),
                    ],
                    'last_message' => $this->formatMessage($message),
                    'unread_count' => 0,
                    'is_starter'   => false,
                ];
            }

            // Messages are desc — last processed per thread is the oldest visible one.
            // is_starter reflects who sent the earliest visible message.
            $threads[$other->id]['is_starter'] = ($message->sender_user_id === $actor->id);

            if ($message->recipient_user_id === $actor->id && ! $message->read_at) {
                $threads[$other->id]['unread_count'] += 1;
            }
        }

        return response()->json(['data' => array_values($threads)]);
    }

    public function threadMessages(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $limit = (int) $request->query('limit', 300);
        if ($limit <= 0) {
            $limit = 300;
        }
        $limit = min($limit, 1000);

        // Apply hidden_before cutoff if this actor has hidden the thread
        $cutoff = DB::table('message_thread_hidden')
            ->where('user_id', $actor->id)
            ->where('other_user_id', $userId)
            ->value('hidden_before');

        $messages = Message::with([
            'sender' => fn ($query) => $query->withTrashed(),
            'recipient' => fn ($query) => $query->withTrashed(),
            'attachment',
        ])
            ->where(function ($query) use ($actor, $userId) {
                $query->where('sender_user_id', $actor->id)
                    ->where('recipient_user_id', $userId);
            })
            ->orWhere(function ($query) use ($actor, $userId) {
                $query->where('sender_user_id', $userId)
                    ->where('recipient_user_id', $actor->id);
            })
            ->when($cutoff, fn ($q) => $q->where('created_at', '>', $cutoff))
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Message $message) => $this->formatMessage($message));

        return response()->json(['data' => $messages]);
    }

    public function markThreadRead(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $updated = Message::where('recipient_user_id', $actor->id)
            ->where('sender_user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Thread marked read.', 'count' => $updated]);
    }

    public function inbox(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $limit = (int) $request->query('limit', 200);
        if ($limit <= 0) {
            $limit = 200;
        }
        $limit = min($limit, 500);

        $messages = Message::with([
            'sender' => fn ($query) => $query->withTrashed(),
            'recipient' => fn ($query) => $query->withTrashed(),
        ])
            ->where('recipient_user_id', $actor->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Message $message) => $this->formatMessage($message));

        return response()->json(['data' => $messages]);
    }

    public function sent(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $limit = (int) $request->query('limit', 200);
        if ($limit <= 0) {
            $limit = 200;
        }
        $limit = min($limit, 500);

        $messages = Message::with([
            'sender' => fn ($query) => $query->withTrashed(),
            'recipient' => fn ($query) => $query->withTrashed(),
        ])
            ->where('sender_user_id', $actor->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Message $message) => $this->formatMessage($message));

        return response()->json(['data' => $messages]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $message = Message::with([
            'sender' => fn ($query) => $query->withTrashed(),
            'recipient' => fn ($query) => $query->withTrashed(),
        ])->findOrFail($id);

        if ($message->sender_user_id !== $actor->id && $message->recipient_user_id !== $actor->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $this->formatMessage($message)]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $message = Message::with([
            'sender' => fn ($query) => $query->withTrashed(),
            'recipient' => fn ($query) => $query->withTrashed(),
        ])->findOrFail($id);

        if ($message->recipient_user_id !== $actor->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $message->read_at) {
            $message->read_at = now();
            $message->save();
        }

        return response()->json(['data' => $this->formatMessage($message)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $message = Message::findOrFail($id);

        if ($message->sender_user_id !== $actor->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $message->delete();

        AuditLogger::log($request, 'staff_message_deleted', $actor, [
            'message_id' => $message->id,
        ]);

        return response()->json(['message' => 'Message deleted.']);
    }

    public function destroyThread(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        DB::table('message_thread_hidden')->upsert(
            [['user_id' => $actor->id, 'other_user_id' => $userId, 'hidden_before' => now()]],
            ['user_id', 'other_user_id'],
            ['hidden_before'],
        );

        AuditLogger::log($request, 'staff_thread_hidden', $actor, [
            'other_user_id' => $userId,
        ]);

        return response()->json(['message' => 'Thread hidden.']);
    }

    public function destroyThreadForEveryone(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Only the thread starter (sender of the first message) may delete for everyone
        $firstMessage = Message::where(function ($query) use ($actor, $userId) {
            $query->where('sender_user_id', $actor->id)
                ->where('recipient_user_id', $userId);
        })->orWhere(function ($query) use ($actor, $userId) {
            $query->where('sender_user_id', $userId)
                ->where('recipient_user_id', $actor->id);
        })->orderBy('created_at')->first();

        if (! $firstMessage || (int) $firstMessage->sender_user_id !== (int) $actor->id) {
            return response()->json(['message' => 'Only the conversation starter can delete for everyone.'], 403);
        }

        $count = Message::where(function ($query) use ($actor, $userId) {
            $query->where('sender_user_id', $actor->id)
                ->where('recipient_user_id', $userId);
        })->orWhere(function ($query) use ($actor, $userId) {
            $query->where('sender_user_id', $userId)
                ->where('recipient_user_id', $actor->id);
        })->delete();

        // Also clear any hidden flags since the messages are gone
        DB::table('message_thread_hidden')
            ->where(function ($q) use ($actor, $userId) {
                $q->where('user_id', $actor->id)->where('other_user_id', $userId);
            })
            ->orWhere(function ($q) use ($actor, $userId) {
                $q->where('user_id', $userId)->where('other_user_id', $actor->id);
            })
            ->delete();

        AuditLogger::log($request, 'staff_thread_deleted_for_everyone', $actor, [
            'other_user_id' => $userId,
            'count'         => $count,
        ]);

        return response()->json(['message' => 'Thread deleted for everyone.', 'count' => $count]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'to_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
            'subject'       => ['nullable', 'string', 'max:255'],
            'body'          => ['nullable', 'string', 'max:2000'],
            'attachment_id' => ['nullable', 'integer', 'exists:message_attachments,id'],
        ]);

        // Must have body, attachment, or both
        if (empty($data['body']) && empty($data['attachment_id'])) {
            return response()->json(['message' => 'A message body or attachment is required.'], 422);
        }

        $recipient = User::findOrFail($data['to_user_id']);

        $message = Message::create([
            'sender_user_id'    => $actor->id,
            'recipient_user_id' => $recipient->id,
            'subject'           => $data['subject'] ?? null,
            'body'              => $data['body'] ?? '',
            'attachment_id'     => $data['attachment_id'] ?? null,
        ]);

        // Link the attachment back to this message
        if (! empty($data['attachment_id'])) {
            MessageAttachment::where('id', $data['attachment_id'])
                ->where('owner_user_id', $actor->id)
                ->update(['message_id' => $message->id]);
        }

        $message->load('attachment');

        AuditLogger::log($request, 'staff_message_sent', $recipient, [
            'message_id'    => $message->id,
            'to_user_id'    => $recipient->id,
            'subject'       => $message->subject,
            'body_length'   => strlen($message->body),
            'has_attachment' => isset($data['attachment_id']),
        ]);

        return response()->json([
            'message' => 'Message sent.',
            'data'    => $this->formatMessage($message),
        ], 201);
    }

    private function formatMessage(Message $message): array
    {
        // attachment() uses HasOne — soft-deleted rows are excluded by default, so $message->attachment is null once deleted
        $attachment = $message->relationLoaded('attachment') ? $message->attachment : null;

        return [
            'id'         => $message->id,
            'subject'    => $message->subject,
            'body'       => $message->body,
            'read_at'    => optional($message->read_at)->toIso8601String(),
            'created_at' => optional($message->created_at)->toIso8601String(),
            'sender'     => $message->sender
                ? [
                    'id'    => $message->sender->id,
                    'name'  => $message->sender->name,
                    'email' => $message->sender->email,
                ]
                : null,
            'recipient' => $message->recipient
                ? [
                    'id'    => $message->recipient->id,
                    'name'  => $message->recipient->name,
                    'email' => $message->recipient->email,
                ]
                : null,
            'attachment' => $attachment
                ? [
                    'id'            => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'mime_type'     => $attachment->mime_type,
                    'size'          => (int) $attachment->size,
                ]
                : null,
        ];
    }
}
