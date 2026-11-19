<?php

namespace App\Http\Controllers;

use App\Events\MessageSeen;
use App\Events\UnreadCountsUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\User;
use App\Support\UnreadCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /** Conversation list for the sidebar (one query + eager loads). */
    public function index(): JsonResponse
    {
        $meUser = auth()->user()->loadMissing('profile');
        $me = (int) $meUser->id;

        $conversations = $meUser->conversations()
            // Message requests are deliberately absent from the normal list
            // until the recipient accepts them.
            ->where(function ($query) {
                $query->doesntHave('messageRequest')
                    ->orWhereHas('messageRequest', fn ($q) => $q->where('status', 'accepted'));
            })
            ->with([
                'participants.profile',
                'participants.activeNote',
                'group' => fn ($q) => $q->withCount('members'),
                'latestMessage' => fn ($q) => $q->visibleTo($me),
                'latestMessage.sender.profile',
                'latestMessage.reads.user.profile',
                'latestMessage.call.caller.profile',
                'latestMessage.call.callee.profile',
            ])
            ->get()
            ->map(function (Conversation $c) use ($me, $meUser) {
                $other = null;

                if ($c->type === 'group' && $c->group) {
                    $name = $c->group->name;
                    $subtitle = $c->group->members_count.' members';
                    $avatar = $c->group->avatar_path
                        ? route('avatars.show', ['filename' => basename($c->group->avatar_path)])
                        : null;
                } else {
                    $other = $c->participants->first(fn (User $u) => $u->id !== $me);
                    $name = $other?->name ?? 'Unknown';
                    $subtitle = $other ? '@'.$other->username : '';
                    $avatar = $other?->avatarUrl();
                }

                $last = $c->latestMessage;

                return [
                    'id' => $c->id,
                    'type' => $c->type,
                    'group_id' => $c->group_id,
                    'peer_id' => $other?->id,
                    'name' => $name,
                    'subtitle' => $subtitle,
                    'avatar' => $avatar,
                    'blocked' => $other ? $meUser->blocksEitherWay((int) $other->id) : false,
                    ...($other
                        ? array_merge($other->notePayload($me), $other->presencePayloadFor($me))
                        : ['has_note' => false, 'note' => null]),
                    'last_message' => $last?->payload($me, $meUser),
                    'last_at' => $last?->created_at?->toISOString(),
                ];
            });

        $unread = UnreadCounter::for($me);

        $list = $conversations->map(fn ($c) => $c + ['unread' => $unread[$c['id']] ?? 0])
            ->sortByDesc(fn ($c) => $c['last_message']['id'] ?? 0)
            ->values();

        return response()->json($list);
    }

    /** Paginated history for one authorized conversation. */
    public function show(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        abort_if($conversation->isPendingRequest(), 403, 'Accept or delete this message request first.');

        $meUser = auth()->user()->loadMissing('profile');
        $me = (int) $meUser->id;
        $newReads = [];

        DB::transaction(function () use ($conversation, $me, &$newReads) {
            $ids = $conversation->messages()
                ->visibleTo($me)
                ->where('sender_id', '!=', $me)
                ->where('is_unsent', false)
                ->where('type', '!=', 'call')
                ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $me))
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $rows = $ids->map(fn ($id) => [
                    'message_id' => $id,
                    'user_id' => $me,
                    'read_at' => now(),
                ])->all();

                MessageRead::insertOrIgnore($rows);

                // insertOrIgnore skips duplicates; announce only rows we created.
                $newReads = MessageRead::where('user_id', $me)
                    ->whereIn('message_id', $ids)
                    ->with(['user.profile', 'message'])
                    ->get()
                    ->all();
            }

            $conversation->participants()->updateExistingPivot($me, ['last_read_at' => now()]);
        });

        // Compute once: the receipts, the cross-tab event and the HTTP
        // response must all agree, even if a websocket event is missed.
        $counts = UnreadCounter::for($me);

        if ($meUser->readReceiptsEnabled()) {
            foreach ($newReads as $read) {
                broadcast(new MessageSeen($read, $counts));
            }
        }

        // Other tabs of the same user should drop their badges too.
        broadcast(new UnreadCountsUpdated($me, $counts));

        $page = $conversation->messages()
            ->visibleTo($me)
            ->with(['sender.profile', 'reads.user.profile', 'attachments', 'call.caller.profile', 'call.callee.profile'])
            ->orderByDesc('id')
            ->cursorPaginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Message $m) => $m->payload($me, $meUser))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'conversation_id' => $conversation->id,
            'unread' => $counts[$conversation->id] ?? 0,
            'counts' => $counts,
        ]);
    }

    /**
     * Persist read receipts for a conversation that is currently visible.
     * Used when messages arrive live or when the tab regains focus, so the
     * server's seen state matches what the recipient actually saw.
     */
    public function markSeen(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        abort_if($conversation->isPendingRequest(), 403, 'Accept or delete this message request first.');

        $meUser = auth()->user()->loadMissing('profile');
        $me = (int) $meUser->id;
        $newReads = [];

        DB::transaction(function () use ($conversation, $me, &$newReads) {
            $ids = $conversation->messages()
                ->visibleTo($me)
                ->where('sender_id', '!=', $me)
                ->where('is_unsent', false)
                ->where('type', '!=', 'call')
                ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $me))
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                MessageRead::insertOrIgnore($ids->map(fn ($id) => [
                    'message_id' => $id,
                    'user_id' => $me,
                    'read_at' => now(),
                ])->all());

                $newReads = MessageRead::where('user_id', $me)
                    ->whereIn('message_id', $ids)
                    ->with(['user.profile', 'message'])
                    ->get()
                    ->all();
            }

            $conversation->participants()->updateExistingPivot($me, ['last_read_at' => now()]);
        });

        $counts = UnreadCounter::for($me);

        if ($meUser->readReceiptsEnabled()) {
            foreach ($newReads as $read) {
                broadcast(new MessageSeen($read, $counts));
            }
        }

        broadcast(new UnreadCountsUpdated($me, $counts));

        return response()->json([
            'status' => 'seen',
            'conversation_id' => (int) $conversation->id,
            'new_reads' => count($newReads),
            'counts' => $counts,
        ]);
    }

    /** Find or create the single 1:1 conversation with an accepted friend. */
    public function storeDirect(User $user): JsonResponse
    {
        $meUser = auth()->user()->loadMissing('profile');
        $user->loadMissing('profile');
        $me = (int) $meUser->id;

        abort_if($user->id === $me, 422, 'You cannot chat with yourself.');
        abort_if($meUser->blocksEitherWay((int) $user->id), 403, 'You cannot start a conversation with this user.');
        abort_unless(
            $meUser->isFriendsWith((int) $user->id),
            403,
            'Only accepted friends can start a conversation. Send a friend request or message request first.',
        );

        $key = Conversation::directKey($me, (int) $user->id);

        $conversation = DB::transaction(function () use ($key, $me, $user) {
            $conversation = Conversation::firstOrCreate(
                ['direct_key' => $key],
                ['type' => 'direct'],
            );

            $conversation->participants()->syncWithoutDetaching([$me, $user->id]);

            return $conversation;
        });

        return response()->json(['id' => $conversation->id]);
    }
}
