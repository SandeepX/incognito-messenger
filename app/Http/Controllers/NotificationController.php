<?php

namespace App\Http\Controllers;

use App\Events\NotificationRead;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $page = auth()->user()->notifications()->latest()->paginate(20);

        return response()->json([
            'data' => $page->through(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => $n->data,
                'read_at' => $n->read_at?->toISOString(),
                'created_at' => $n->created_at?->toISOString(),
            ]),
            'unread_count' => auth()->user()->notifications()->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Notification $notification): JsonResponse
    {
        $this->authorize('view', $notification);

        $userId = (int) $notification->user_id;
        $wasUnread = $notification->read_at === null;

        if ($wasUnread) {
            $notification->update(['read_at' => now()]);
        }

        $unreadCount = auth()->user()->notifications()->whereNull('read_at')->count();

        if ($wasUnread) {
            broadcast(new NotificationRead($userId, (int) $notification->id, $unreadCount));
        }

        return response()->json([
            'status' => 'read',
            'notification_id' => (int) $notification->id,
            'unread_count' => $unreadCount,
        ]);
    }

    public function markAllRead(): JsonResponse
    {
        $user = auth()->user();
        $user->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        broadcast(new NotificationRead((int) $user->id, null, 0));

        return response()->json(['status' => 'read', 'unread_count' => 0]);
    }
}
