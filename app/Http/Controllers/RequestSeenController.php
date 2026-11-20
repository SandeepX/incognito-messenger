<?php

namespace App\Http\Controllers;

use App\Models\FriendRequest;
use App\Models\MessageRequest;
use Illuminate\Http\JsonResponse;

class RequestSeenController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $me = auth()->id();
        $now = now();

        FriendRequest::query()
            ->where('recipient_id', $me)
            ->where('status', 'pending')
            ->whereNull('seen_at')
            ->update(['seen_at' => $now]);

        MessageRequest::query()
            ->where('recipient_id', $me)
            ->where('status', 'pending')
            ->whereNull('seen_at')
            ->update(['seen_at' => $now]);

        $friendUnseen = FriendRequest::query()
            ->where('recipient_id', $me)
            ->where('status', 'pending')
            ->whereNull('seen_at')
            ->count();

        $messageUnseen = MessageRequest::query()
            ->where('recipient_id', $me)
            ->where('status', 'pending')
            ->whereNull('seen_at')
            ->count();

        return response()->json([
            'status' => 'seen',
            'counts' => [
                'friend_unseen' => $friendUnseen,
                'message_unseen' => $messageUnseen,
                'unseen' => $friendUnseen + $messageUnseen,
            ],
        ]);
    }
}
