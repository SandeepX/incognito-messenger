<?php

namespace App\Http\Controllers;

use App\Events\PresenceUpdated;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PresenceController extends Controller
{
    /**
     * Presence for everyone the caller is allowed to see: accepted friends
     * plus participants of normal conversations. Pending message requests
     * are deliberately excluded.
     */
    public function index(): JsonResponse
    {
        $me = auth()->user();
        $ids = $this->audience($me);

        $users = User::whereIn('id', $ids)
            ->with('profile')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => array_merge([
                'id' => (int) $user->id,
                'name' => $user->name,
            ], $user->presencePayload()));

        return response()->json($users);
    }

    /** Called on socket connect and every 30 seconds while the page is open. */
    public function heartbeat(): JsonResponse
    {
        return $this->touch(true);
    }

    /** Called via sendBeacon when the page/socket goes away. */
    public function disconnect(): JsonResponse
    {
        return $this->touch(false);
    }

    private function touch(bool $online): JsonResponse
    {
        $user = auth()->user();
        $user->last_active_at = now();
        $user->save();
        $user->loadMissing('profile');

        broadcast(new PresenceUpdated($user, $online, $this->audience($user)));

        return response()->json([
            'ok' => true,
            'last_active_at' => $user->last_active_at?->toISOString(),
        ]);
    }

    /** @return array<int, int> */
    private function audience(User $user): array
    {
        $conversationIds = DB::table('conversation_participants')
            ->where('user_id', $user->id)
            ->pluck('conversation_id');

        $normalConversationIds = Conversation::whereIn('id', $conversationIds)
            ->where(function ($query) {
                $query->doesntHave('messageRequest')
                    ->orWhereHas('messageRequest', fn ($q) => $q->where('status', 'accepted'));
            })
            ->pluck('id');

        $sharedUserIds = DB::table('conversation_participants')
            ->whereIn('conversation_id', $normalConversationIds)
            ->pluck('user_id');

        $ids = array_merge([$user->id], $user->friendIds(), $sharedUserIds->all());

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
