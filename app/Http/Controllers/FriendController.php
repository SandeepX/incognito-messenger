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
        $viewerId ??= auth()->id();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar' => $user->avatarUrl(),
            'status' => $user->profile?->status,
            'bio' => $user->profile?->bio,
            ...$user->notePayload($viewerId),
            ...$user->presencePayload(),
        ];
    }

    /** Friends, incoming and outgoing requests in one payload. */
    public function index(): JsonResponse
    {
        $me = auth()->user();

        $friends = $me->friends()->with(['profile', 'activeNote'])->latest('friendships.created_at')
            ->paginate(20)
            ->through(fn (User $u) => $this->userPayload($u, $me->id));

        $incoming = $me->receivedFriendRequests()->where('status', 'pending')
            ->with(['sender.profile', 'sender.activeNote'])->latest()->paginate(20)
            ->through(fn (FriendRequest $r) => [
                'id' => $r->id,
                'user' => $this->userPayload($r->sender, $me->id),
                'note' => $r->note,
                'created_at' => $r->created_at?->toISOString(),
            ]);

        $outgoing = $me->sentFriendRequests()->where('status', 'pending')
            ->with(['recipient.profile', 'recipient.activeNote'])->latest()->paginate(20)
            ->through(fn (FriendRequest $r) => [
                'id' => $r->id,
                'user' => $this->userPayload($r->recipient, $me->id),
                'note' => $r->note,
                'created_at' => $r->created_at?->toISOString(),
            ]);

        return response()->json([
            'friends' => $friends,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'counts' => [
                'friends' => $friends->total(),
                'incoming' => $incoming->total(),
                'outgoing' => $outgoing->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:160'],
        ]);

        // Notes are optional: an omitted or blank note is stored as null.
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        $target = User::findOrFail($data['user_id']);
        $me = auth()->id();

        abort_if($target->id === $me, 422, 'You cannot send a friend request to yourself.');
        abort_if(auth()->user()->isFriendsWith($target->id), 422, 'You are already friends.');

        $key = PairKey::for($me, $target->id);

        $existing = FriendRequest::where('pair_key', $key)->first();

        if ($existing?->status === 'pending') {
            abort_if($existing->sender_id === $me, 422, 'A friend request is already pending.');
            // The other person already asked us — let them know we want to connect too.
            $this->createFriendship($existing);
            Notifier::send($existing->sender_id, 'friend_request_accepted', [
                'user_id' => $me,
                'name' => auth()->user()->name,
                'avatar' => auth()->user()->avatarUrl(),
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

        Notifier::send($target->id, 'friend_request_received', [
            'user_id' => $me,
            'name' => auth()->user()->name,
            'username' => auth()->user()->username,
            'avatar' => auth()->user()->avatarUrl(),
            'request_id' => $friendRequest->id,
            'note' => $friendRequest->note,
        ]);

        return response()->json([
            'status' => 'pending',
            'id' => $friendRequest->id,
            'note' => $friendRequest->note,
        ], 201);
    }

    public function accept(FriendRequest $friendRequest): JsonResponse
    {
        $this->authorize('respond', $friendRequest);

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

        return response()->json(['status' => 'accepted', 'friend' => $this->userPayload($friendRequest->sender)]);
    }

    public function reject(FriendRequest $friendRequest): JsonResponse
    {
        $this->authorize('respond', $friendRequest);

        $friendRequest->update(['status' => 'rejected', 'responded_at' => now()]);

        Notifier::send($friendRequest->sender_id, 'friend_request_rejected', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
        ]);

        return response()->json(['status' => 'rejected']);
    }

    public function cancel(FriendRequest $friendRequest): JsonResponse
    {
        $this->authorize('cancel', $friendRequest);

        $friendRequest->update(['status' => 'cancelled', 'responded_at' => now()]);

        Notifier::send($friendRequest->recipient_id, 'friend_request_cancelled', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
        ]);

        return response()->json(['status' => 'cancelled']);
    }

    /** Remove an accepted friend (both friendship rows). */
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
