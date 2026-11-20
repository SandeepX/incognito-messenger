<?php

namespace App\Http\Controllers;

use App\Events\ConversationsUpdated;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\User;
use App\Support\Notifier;
use App\Support\PairKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FriendController extends Controller
{
    private function userPayload(User $user, ?int $viewerId = null): array
    {
        $viewerId ??= (int) auth()->id();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar' => $user->avatarUrl(),
            'status' => $user->profile?->status,
            'bio' => $user->profile?->bio,
            ...$user->notePayload((int) $viewerId),
            ...$user->presencePayloadFor((int) $viewerId),
        ];
    }

    private function requestPayload(FriendRequest $request, string $direction, int $viewerId): array
    {
        $other = $direction === 'incoming' ? $request->sender : $request->recipient;

        return [
            'id' => $request->id,
            'direction' => $direction,
            'status' => $request->status,
            'type' => 'friend',
            'user' => $this->userPayload($other, $viewerId),
            'note' => $request->note,
            'created_at' => $request->created_at?->toISOString(),
            'responded_at' => $request->responded_at?->toISOString(),
            'seen_at' => $request->seen_at?->toISOString(),
            'unseen' => $direction === 'incoming' && $request->status === 'pending' && $request->seen_at === null,
        ];
    }

    public function index(): JsonResponse
    {
        $me = auth()->user();

        $friends = $me->friends()->with(['profile', 'activeNote'])->latest('friendships.created_at')
            ->get()
            ->map(fn (User $u) => $this->userPayload($u, $me->id))
            ->values();

        $incoming = $me->receivedFriendRequests()->where('status', 'pending')
            ->with(['sender.profile', 'sender.activeNote'])->latest()
            ->get()
            ->map(fn (FriendRequest $r) => $this->requestPayload($r, 'incoming', $me->id))
            ->values();

        $outgoing = $me->sentFriendRequests()->where('status', 'pending')
            ->with(['recipient.profile', 'recipient.activeNote'])->latest()
            ->get()
            ->map(fn (FriendRequest $r) => $this->requestPayload($r, 'outgoing', $me->id))
            ->values();

        return response()->json([
            'friends' => $friends,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'counts' => [
                'friends' => $friends->count(),
                'incoming' => $incoming->count(),
                'outgoing' => $outgoing->count(),
                'unseen_incoming' => $incoming->where('unseen', true)->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:160'],
        ]);

        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $target = User::findOrFail($data['user_id']);
        $meUser = auth()->user()->loadMissing('profile');
        $target->loadMissing('profile');
        $me = (int) $meUser->id;

        abort_if($target->id === $me, 422, 'You cannot send a friend request to yourself.');
        abort_if($meUser->blocksEitherWay((int) $target->id), 403, 'You cannot send a friend request to this user.');
        abort_if($meUser->isFriendsWith((int) $target->id), 422, 'You are already friends.');

        $key = PairKey::for($me, (int) $target->id);
        $existing = FriendRequest::where('pair_key', $key)->first();

        if ($existing?->status === 'pending') {
            abort_if($existing->sender_id === $me, 422, 'A friend request is already pending.');
            $this->createFriendship($existing);
            Notifier::send($existing->sender_id, 'friend_request_accepted', [
                'user_id' => $me,
                'name' => $meUser->name,
                'avatar' => $meUser->avatarUrl(),
            ]);
            broadcast(new ConversationsUpdated($existing->sender_id));
            broadcast(new ConversationsUpdated($me));

            return response()->json(['status' => 'accepted', 'friend_request_id' => $existing->id], 201);
        }

        if ($existing) {
            abort_if($existing->status === 'accepted', 422, 'You are already friends.');
            $existing->update([
                'status' => 'pending',
                'responded_at' => null,
                'seen_at' => null,
                'sender_id' => $me,
                'recipient_id' => $target->id,
                'note' => $note,
            ]);
            $friendRequest = $existing;
        } else {
            $friendRequest = FriendRequest::create([
                'sender_id' => $me,
                'recipient_id' => $target->id,
                'pair_key' => $key,
                'status' => 'pending',
                'note' => $note,
            ]);
        }

        Notifier::send((int) $target->id, 'friend_request_received', [
            'user_id' => $me,
            'name' => $meUser->name,
            'username' => $meUser->username,
            'avatar' => $meUser->avatarUrl(),
            'request_id' => $friendRequest->id,
            'note' => $friendRequest->note,
        ]);

        return response()->json([
            'status' => 'pending',
            'id' => $friendRequest->id,
            'note' => $friendRequest->note,
            'created_at' => $friendRequest->created_at?->toISOString(),
        ], 201);
    }

    public function accept(FriendRequest $friendRequest): JsonResponse
    {
        $this->authorize('respond', $friendRequest);
        abort_unless($friendRequest->status === 'pending', 409, 'This request is no longer pending.');

        DB::transaction(function () use ($friendRequest) {
            $friendRequest->update(['status' => 'accepted', 'responded_at' => now()]);
            $this->createFriendship($friendRequest);
        });

        Notifier::send($friendRequest->sender_id, 'friend_request_accepted', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
            'avatar' => auth()->user()->avatarUrl(),
        ]);
        broadcast(new ConversationsUpdated($friendRequest->sender_id));
        broadcast(new ConversationsUpdated(auth()->id()));

        return response()->json([
            'status' => 'accepted',
            'id' => $friendRequest->id,
            'friend' => $this->userPayload($friendRequest->sender),
        ]);
    }

    public function reject(FriendRequest $friendRequest): JsonResponse
    {
        $this->authorize('respond', $friendRequest);
        abort_unless($friendRequest->status === 'pending', 409, 'This request is no longer pending.');

        $friendRequest->update(['status' => 'rejected', 'responded_at' => now()]);

        Notifier::send($friendRequest->sender_id, 'friend_request_rejected', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
        ]);

        return response()->json(['status' => 'rejected', 'id' => $friendRequest->id]);
    }

    public function cancel(FriendRequest $friendRequest): JsonResponse
    {
        $this->authorize('cancel', $friendRequest);
        abort_unless($friendRequest->status === 'pending', 409, 'This request is no longer pending.');

        $friendRequest->update(['status' => 'cancelled', 'responded_at' => now()]);

        Notifier::send($friendRequest->recipient_id, 'friend_request_cancelled', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
        ]);

        return response()->json(['status' => 'cancelled', 'id' => $friendRequest->id]);
    }

    public function undoReject(FriendRequest $friendRequest): JsonResponse
    {
        $this->authorize('respond', $friendRequest);
        abort_unless($friendRequest->status === 'rejected', 409, 'Cannot undo this action.');

        $friendRequest->update(['status' => 'pending', 'responded_at' => null, 'seen_at' => now()]);

        return response()->json(['status' => 'pending', 'id' => $friendRequest->id]);
    }

    public function undoCancel(FriendRequest $friendRequest): JsonResponse
    {
        $this->authorize('cancel', $friendRequest);
        abort_unless($friendRequest->status === 'cancelled', 409, 'Cannot undo this action.');

        $friendRequest->update(['status' => 'pending', 'responded_at' => null, 'seen_at' => null]);

        Notifier::send($friendRequest->recipient_id, 'friend_request_received', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
            'username' => auth()->user()->username,
            'avatar' => auth()->user()->avatarUrl(),
            'request_id' => $friendRequest->id,
            'note' => $friendRequest->note,
        ]);

        return response()->json(['status' => 'pending', 'id' => $friendRequest->id]);
    }

    public function undoAccept(FriendRequest $friendRequest): JsonResponse
    {
        $this->authorize('respond', $friendRequest);
        abort_unless($friendRequest->status === 'accepted', 409, 'Cannot undo this action.');

        $a = (int) $friendRequest->sender_id;
        $b = (int) $friendRequest->recipient_id;

        DB::transaction(function () use ($friendRequest, $a, $b) {
            Friendship::whereIn('pair_key', [$a.'-'.$b, $b.'-'.$a])->delete();
            $friendRequest->update(['status' => 'pending', 'responded_at' => null, 'seen_at' => now()]);
        });

        broadcast(new ConversationsUpdated($a));
        broadcast(new ConversationsUpdated($b));

        return response()->json(['status' => 'pending', 'id' => $friendRequest->id]);
    }

    public function destroy(User $user): JsonResponse
    {
        abort_if($user->id === auth()->id(), 422, 'You cannot remove yourself.');

        $me = auth()->id();
        Friendship::whereIn('pair_key', [$me.'-'.$user->id, $user->id.'-'.$me])->delete();

        FriendRequest::where('pair_key', PairKey::for($me, $user->id))
            ->update(['status' => 'cancelled', 'responded_at' => now()]);

        broadcast(new ConversationsUpdated(auth()->id()));
        broadcast(new ConversationsUpdated($user->id));

        return response()->json(['status' => 'removed']);
    }

    private function createFriendship(FriendRequest $request): void
    {
        $a = (int) $request->sender_id;
        $b = (int) $request->recipient_id;

        Friendship::firstOrCreate(
            ['user_id' => $a, 'friend_id' => $b],
            ['pair_key' => $a.'-'.$b],
        );
        Friendship::firstOrCreate(
            ['user_id' => $b, 'friend_id' => $a],
            ['pair_key' => $b.'-'.$a],
        );
    }
}
