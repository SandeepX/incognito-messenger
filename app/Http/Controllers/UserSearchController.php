<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    /**
     * Search registered users by display name or username.
     *
     * The sidebar's "Find friends" picker passes scope=find-friends. That
     * scope applies friend/conversation/pending-request exclusions on the
     * server; the unscoped query remains available for group member pickers.
     */
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));
        $me = auth()->user()->loadMissing('profile');
        $findFriends = $request->input('scope') === 'find-friends';
        $blockedIds = array_values(array_unique(array_merge(
            $me->blockedUserIds(),
            $me->blockedBy()->pluck('blocker_id')->map(fn ($id) => (int) $id)->all(),
        )));

        $users = User::query()
            ->with(['profile', 'activeNote'])
            ->whereKeyNot($me->id)
            ->when($blockedIds, fn ($q) => $q->whereNotIn('id', $blockedIds))
            ->when($findFriends, function ($q) use ($me) {
                $q->whereNotIn('id', $me->friendIds())
                    // Pending in either direction: no duplicate or stale invites.
                    ->whereDoesntHave('sentFriendRequests', fn ($r) => $r
                        ->where('status', 'pending')
                        ->where('recipient_id', $me->id))
                    ->whereDoesntHave('receivedFriendRequests', fn ($r) => $r
                        ->where('status', 'pending')
                        ->where('sender_id', $me->id))
                    // Any shared thread (direct chat, group, or a message
                    // request conversation) already exists: hide the user.
                    ->whereDoesntHave('conversations', fn ($c) => $c
                        ->whereHas('participants', fn ($p) => $p->where('user_id', $me->id)));
            })
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($inner) use ($query) {
                    $inner->where('name', 'like', '%'.$query.'%')
                        ->orWhereHas('profile', fn ($p) => $p->where('username', 'like', '%'.$query.'%'));
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        // One query for all existing threads instead of one per user.
        $conversationIds = Conversation::whereIn(
            'direct_key',
            $users->map(fn (User $u) => Conversation::directKey((int) $me->id, (int) $u->id)),
        )->pluck('id', 'direct_key');

        $viewerId = (int) $me->id;
        $results = $users->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar' => $user->avatarUrl(),
            'is_friend' => $me->isFriendsWith((int) $user->id),
            ...$user->notePayload($viewerId),
            'conversation_id' => $conversationIds[Conversation::directKey($viewerId, (int) $user->id)] ?? null,
            ...$user->presencePayloadFor($viewerId),
        ])->values();

        if ($findFriends) {
            $results = $results->filter(function (array $row) use ($me, $users) {
                $target = $users->firstWhere('id', $row['id']);

                return $target ? $target->canReceiveMessageRequestFrom($me) : false;
            })->values();
        }

        return response()->json($results);
    }
}
