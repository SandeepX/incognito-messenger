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
        $me = auth()->id();

        $conversations = auth()->user()->conversations()
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
                'latestMessage.sender.profile',
            ])
            ->get()
            ->map(function (Conversation $c) use ($me) {
                $other = null;

                if ($c->type === 'group' && $c->group) {
                    $name = $c->group->name;
                    $subtitle = $c->group->members_count.' members';
                    $avatar = $c->group->avatar_path
                        ? route('avatars.show', ['filename' => basename($c->group->avatar_path)])
                        : asset('images/default-avatar.svg');
                } else {
                    $other = $c->participants->first(fn (User $u) => $u->id !== $me);
                    $name = $other?->name ?? 'Unknown';
                    $subtitle = $other ? '@'.$other->username : '';
                    $avatar = $other?->avatarUrl() ?? asset('images/default-avatar.svg');
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
                    'has_note' => $other?->activeNote !== null,
                    'last_message' => $last?->payload(),
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

        $me = auth()->id();
        $newReads = [];

        DB::transaction(function () use ($conversation, $me, &$newReads) {
            $ids = $conversation->messages()
                ->where('sender_id', '!=', $me)
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

        foreach ($newReads as $read) {
            broadcast(new MessageSeen($read, $counts));
        }

        // Other tabs of the same user should drop their badges too.
        broadcast(new UnreadCountsUpdated($me, $counts));

        $page = $conversation->messages()
            ->with(['sender.profile', 'reads.user.profile', 'attachments'])
            ->orderByDesc('id')
            ->cursorPaginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Message $m) => $m->payload())->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'conversation_id' => $conversation->id,
            'unread' => $counts[$conversation->id] ?? 0,
            'counts' => $counts,
        ]);
    }

    /** Find or create the single 1:1 conversation with an accepted friend. */
    public function storeDirect(User $user): JsonResponse
    {
        $me = auth()->id();

        abort_if($user->id === $me, 422, 'You cannot chat with yourself.');
        abort_unless(
            auth()->user()->isFriendsWith($user->id),
            403,
            'Only accepted friends can start a conversation. Send a friend request or message request first.',
        );

        $key = Conversation::directKey($me, $user->id);

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
