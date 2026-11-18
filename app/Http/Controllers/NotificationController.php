<?php

namespace App\Http\Controllers;

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

        $notification->update(['read_at' => now()]);

        return response()->json([
            'status' => 'read',
            'unread_count' => auth()->user()->notifications()->whereNull('read_at')->count(),
        ]);
    }

    public function markAllRead(): JsonResponse
    {
        auth()->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['status' => 'read', 'unread_count' => 0]);
    }
}
