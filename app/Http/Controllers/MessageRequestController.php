<?php

namespace App\Http\Controllers;

use App\Events\ConversationsUpdated;
use App\Events\MessageSent;
use App\Events\UnreadCountsUpdated;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\MessageRequest;
use App\Models\User;
use App\Support\Notifier;
use App\Support\PairKey;
use App\Support\UnreadCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageRequestController extends Controller
{
    private function payload(MessageRequest $request, string $side): array
    {
        $other = $side === 'incoming' ? $request->sender : $request->recipient;

        return [
            'id' => $request->id,
            'conversation_id' => $request->conversation_id,
            'status' => $request->status,
            // The `user` below is always the OTHER person, so tell the UI
            // which side of the request the current user is on.
            'direction' => $side,
            'user' => [
                'id' => $other->id,
                'name' => $other->name,
                'username' => $other->username,
                'avatar' => $other->avatarUrl(),
                'bio' => $other->profile?->bio,
                'status_text' => $other->profile?->status,
                'has_note' => $other->relationLoaded('activeNote')
                    ? $other->activeNote !== null
                    : $other->activeNote()->exists(),
            ],
            'preview' => $request->body,
            'created_at' => $request->created_at?->toISOString(),
        ];
    }

    public function index(): JsonResponse
    {
        $me = auth()->id();

        $incoming = MessageRequest::where('recipient_id', $me)->where('status', 'pending')
            ->with(['sender.profile', 'sender.activeNote'])->latest()->paginate(20)
            ->through(fn (MessageRequest $r) => $this->payload($r, 'incoming'));

        $sent = MessageRequest::where('sender_id', $me)->where('status', 'pending')
            ->with(['recipient.profile', 'recipient.activeNote'])->latest()->paginate(20)
            ->through(fn (MessageRequest $r) => $this->payload($r, 'sent'));

        return response()->json([
            'incoming' => $incoming,
            'sent' => $sent,
            'counts' => ['incoming' => $incoming->total(), 'sent' => $sent->total()],
        ]);
    }

    /** Preview the request plus sender profile and the short thread. */
    public function show(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('view', $messageRequest);

        $messageRequest->load([
            'sender.profile',
            'sender.activeNote',
            'recipient.profile',
            'recipient.activeNote',
        ]);
        $side = $messageRequest->sender_id === auth()->id() ? 'sent' : 'incoming';

        $messages = $messageRequest->conversation->messages()
            ->with(['sender.profile', 'reads.user.profile', 'attachments'])
            ->latest()->limit(20)->get()->reverse()->values()
            ->map(fn (Message $m) => $m->payload())->all();

        return response()->json($this->payload($messageRequest, $side) + ['messages' => $messages]);
    }

    /** Start a request with someone who is not yet a friend. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $target = User::findOrFail($data['user_id']);
        $me = auth()->user();

        abort_if($target->id === $me->id, 422, 'You cannot message yourself.');
        abort_if($me->isFriendsWith($target->id), 422, 'You are already friends — open the chat instead.');

        $key = PairKey::for($me->id, $target->id);

        abort_if(
            MessageRequest::where('pair_key', $key)->where('status', 'pending')->exists(),
            422,
            'A message request is already pending for this person.',
        );

        $result = DB::transaction(function () use ($target, $me, $key, $data) {
            $directKey = Conversation::directKey($me->id, $target->id);

            $conversation = Conversation::firstOrCreate(
                ['direct_key' => $directKey],
                ['type' => 'direct'],
            );
            $conversation->participants()->syncWithoutDetaching([$me->id, $target->id]);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $me->id,
                'body' => $data['body'],
            ]);
            MessageRead::create([
                'message_id' => $message->id,
                'user_id' => $me->id,
                'read_at' => now(),
            ]);

            $messageRequest = MessageRequest::updateOrCreate(
                ['pair_key' => $key],
                [
                    'sender_id' => $me->id,
                    'recipient_id' => $target->id,
                    'conversation_id' => $conversation->id,
                    'body' => $data['body'],
                    'status' => 'pending',
                    'responded_at' => null,
                ],
            );

            return [$messageRequest, $message, $target, $me];
        });

        [$messageRequest, $message, $target, $me] = $result;

        $message->load('sender.profile');
        broadcast(new MessageSent($message));
        broadcast(new UnreadCountsUpdated($target->id, UnreadCounter::for($target->id)));
        broadcast(new ConversationsUpdated($me->id));
        broadcast(new ConversationsUpdated($target->id));

        Notifier::send($target->id, 'message_request_received', [
            'user_id' => $me->id,
            'name' => $me->name,
            'username' => $me->username,
            'avatar' => $me->avatarUrl(),
            'preview' => $data['body'],
            'request_id' => $messageRequest->id,
        ]);

        return response()->json([
            'id' => $messageRequest->id,
            'conversation_id' => $messageRequest->conversation_id,
            'status' => 'pending',
        ], 201);
    }

    public function accept(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('respond', $messageRequest);

        DB::transaction(function () use ($messageRequest) {
            $messageRequest->update(['status' => 'accepted', 'responded_at' => now()]);

            $a = (int) $messageRequest->sender_id;
            $b = (int) $messageRequest->recipient_id;
            Friendship::firstOrCreate(['user_id' => $a, 'friend_id' => $b], ['pair_key' => $a.'-'.$b]);
            Friendship::firstOrCreate(['user_id' => $b, 'friend_id' => $a], ['pair_key' => $b.'-'.$a]);
        });

        Notifier::send($messageRequest->sender_id, 'message_request_accepted', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
            'conversation_id' => $messageRequest->conversation_id,
        ]);
        broadcast(new ConversationsUpdated($messageRequest->sender_id));
        broadcast(new ConversationsUpdated($messageRequest->recipient_id));

        return response()->json(['status' => 'accepted', 'conversation_id' => $messageRequest->conversation_id]);
    }

    /** Recipient deletes / rejects an incoming request. */
    public function delete(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('respond', $messageRequest);

        $messageRequest->update(['status' => 'deleted', 'responded_at' => now()]);

        Notifier::send($messageRequest->sender_id, 'message_request_deleted', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
        ]);
        broadcast(new ConversationsUpdated($messageRequest->sender_id));

        return response()->json(['status' => 'deleted']);
    }

    /** Sender withdraws their own pending request. */
    public function cancel(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('cancel', $messageRequest);

        $messageRequest->update(['status' => 'cancelled', 'responded_at' => now()]);

        Notifier::send($messageRequest->recipient_id, 'message_request_cancelled', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
        ]);
        broadcast(new ConversationsUpdated($messageRequest->recipient_id));

        return response()->json(['status' => 'cancelled']);
    }
}
