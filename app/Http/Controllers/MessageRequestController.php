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
use App\Models\UserBlock;
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
        $viewerId = (int) auth()->id();
        $other = $side === 'incoming' ? $request->sender : $request->recipient;

        return [
            'id' => $request->id,
            'conversation_id' => $request->conversation_id,
            'status' => $request->status,
            'type' => 'message',
            'direction' => $side,
            'user' => [
                'id' => $other->id,
                'name' => $other->name,
                'username' => $other->username,
                'avatar' => $other->avatarUrl(),
                'bio' => $other->profile?->bio,
                'status_text' => $other->profile?->status,
                ...$other->notePayload($viewerId),
                ...$other->presencePayloadFor($viewerId),
            ],
            'preview' => $request->body,
            'created_at' => $request->created_at?->toISOString(),
            'responded_at' => $request->responded_at?->toISOString(),
            'seen_at' => $request->seen_at?->toISOString(),
            'unseen' => $side === 'incoming' && $request->status === 'pending' && $request->seen_at === null,
        ];
    }

    public function index(): JsonResponse
    {
        $me = auth()->id();

        $incoming = MessageRequest::where('recipient_id', $me)->where('status', 'pending')
            ->with(['sender.profile', 'sender.activeNote'])->latest()
            ->get()
            ->map(fn (MessageRequest $r) => $this->payload($r, 'incoming'))
            ->values();

        $sent = MessageRequest::where('sender_id', $me)->where('status', 'pending')
            ->with(['recipient.profile', 'recipient.activeNote'])->latest()
            ->get()
            ->map(fn (MessageRequest $r) => $this->payload($r, 'sent'))
            ->values();

        return response()->json([
            'incoming' => $incoming,
            'sent' => $sent,
            'counts' => [
                'incoming' => $incoming->count(),
                'sent' => $sent->count(),
                'unseen_incoming' => $incoming->where('unseen', true)->count(),
            ],
        ]);
    }

    public function show(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('view', $messageRequest);

        $messageRequest->load([
            'sender.profile',
            'sender.activeNote',
            'recipient.profile',
            'recipient.activeNote',
        ]);
        $viewer = auth()->user()->loadMissing('profile');
        $side = $messageRequest->sender_id === $viewer->id ? 'sent' : 'incoming';

        $viewerId = (int) $viewer->id;
        $messages = $messageRequest->conversation->messages()
            ->visibleTo($viewerId)
            ->with(['sender.profile', 'reads.user.profile', 'attachments'])
            ->latest()->limit(20)->get()->reverse()->values()
            ->map(fn (Message $m) => $m->payload($viewerId, $viewer))->all();

        return response()->json($this->payload($messageRequest, $side) + ['messages' => $messages]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $target = User::findOrFail($data['user_id']);
        $me = auth()->user()->loadMissing('profile');
        $target->loadMissing('profile');

        abort_if($target->id === $me->id, 422, 'You cannot message yourself.');
        abort_if($me->isFriendsWith((int) $target->id), 422, 'You are already friends — open the chat instead.');
        abort_if($target->blocksEitherWay((int) $me->id), 403, 'You cannot send a message request to this user.');
        abort_unless($target->canReceiveMessageRequestFrom($me), 403, 'This user is not accepting message requests.');

        $key = PairKey::for((int) $me->id, (int) $target->id);

        abort_if(
            MessageRequest::where('pair_key', $key)->where('status', 'pending')->exists(),
            422,
            'A message request is already pending for this person.',
        );

        $result = DB::transaction(function () use ($target, $me, $key, $data) {
            $directKey = Conversation::directKey((int) $me->id, (int) $target->id);

            $conversation = Conversation::firstOrCreate(
                ['direct_key' => $directKey],
                ['type' => 'direct'],
            );
            $conversation->participants()->syncWithoutDetaching([(int) $me->id, (int) $target->id]);

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
                    'seen_at' => null,
                ],
            );

            return [$messageRequest, $message];
        });

        [$messageRequest, $message] = $result;

        $message->load('sender.profile');
        broadcast(new MessageSent($message));
        broadcast(new UnreadCountsUpdated((int) $target->id, UnreadCounter::for((int) $target->id)));
        broadcast(new ConversationsUpdated((int) $me->id));
        broadcast(new ConversationsUpdated((int) $target->id));

        Notifier::send((int) $target->id, 'message_request_received', [
            'user_id' => (int) $me->id,
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
            'created_at' => $messageRequest->created_at?->toISOString(),
        ], 201);
    }

    public function accept(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('respond', $messageRequest);
        abort_unless($messageRequest->status === 'pending', 409, 'This request is no longer pending.');

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

        return response()->json([
            'status' => 'accepted',
            'id' => $messageRequest->id,
            'conversation_id' => $messageRequest->conversation_id,
        ]);
    }

    public function delete(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('respond', $messageRequest);
        abort_unless($messageRequest->status === 'pending', 409, 'This request is no longer pending.');

        $messageRequest->update(['status' => 'deleted', 'responded_at' => now()]);

        Notifier::send($messageRequest->sender_id, 'message_request_deleted', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
        ]);
        broadcast(new ConversationsUpdated($messageRequest->sender_id));
        broadcast(new ConversationsUpdated($messageRequest->recipient_id));

        return response()->json(['status' => 'deleted', 'id' => $messageRequest->id]);
    }

    public function block(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('respond', $messageRequest);
        abort_unless($messageRequest->status === 'pending', 409, 'This request is no longer pending.');

        DB::transaction(function () use ($messageRequest) {
            UserBlock::firstOrCreate([
                'blocker_id' => (int) auth()->id(),
                'blocked_id' => (int) $messageRequest->sender_id,
            ]);
            $messageRequest->update(['status' => 'deleted', 'responded_at' => now()]);
        });

        Notifier::send($messageRequest->sender_id, 'message_request_deleted', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
        ]);
        broadcast(new ConversationsUpdated($messageRequest->sender_id));
        broadcast(new ConversationsUpdated($messageRequest->recipient_id));

        return response()->json(['status' => 'blocked', 'id' => $messageRequest->id]);
    }

    public function report(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('respond', $messageRequest);
        abort_unless($messageRequest->status === 'pending', 409, 'This request is no longer pending.');

        $messageRequest->update(['status' => 'deleted', 'responded_at' => now()]);

        Notifier::send($messageRequest->sender_id, 'message_request_deleted', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
        ]);
        broadcast(new ConversationsUpdated($messageRequest->sender_id));
        broadcast(new ConversationsUpdated($messageRequest->recipient_id));

        return response()->json(['status' => 'reported', 'id' => $messageRequest->id]);
    }

    public function cancel(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('cancel', $messageRequest);
        abort_unless($messageRequest->status === 'pending', 409, 'This request is no longer pending.');

        $messageRequest->update(['status' => 'cancelled', 'responded_at' => now()]);

        Notifier::send($messageRequest->recipient_id, 'message_request_cancelled', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
        ]);
        broadcast(new ConversationsUpdated($messageRequest->sender_id));
        broadcast(new ConversationsUpdated($messageRequest->recipient_id));

        return response()->json(['status' => 'cancelled', 'id' => $messageRequest->id]);
    }

    public function undoDelete(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('respond', $messageRequest);
        abort_unless($messageRequest->status === 'deleted', 409, 'Cannot undo this action.');

        $messageRequest->update(['status' => 'pending', 'responded_at' => null, 'seen_at' => now()]);

        broadcast(new ConversationsUpdated($messageRequest->sender_id));
        broadcast(new ConversationsUpdated($messageRequest->recipient_id));

        return response()->json(['status' => 'pending', 'id' => $messageRequest->id]);
    }

    public function undoCancel(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('cancel', $messageRequest);
        abort_unless($messageRequest->status === 'cancelled', 409, 'Cannot undo this action.');

        $messageRequest->update(['status' => 'pending', 'responded_at' => null, 'seen_at' => null]);

        Notifier::send($messageRequest->recipient_id, 'message_request_received', [
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
            'username' => auth()->user()->username,
            'avatar' => auth()->user()->avatarUrl(),
            'preview' => $messageRequest->body,
            'request_id' => $messageRequest->id,
        ]);
        broadcast(new ConversationsUpdated($messageRequest->sender_id));
        broadcast(new ConversationsUpdated($messageRequest->recipient_id));

        return response()->json(['status' => 'pending', 'id' => $messageRequest->id]);
    }

    public function undoAccept(MessageRequest $messageRequest): JsonResponse
    {
        $this->authorize('respond', $messageRequest);
        abort_unless($messageRequest->status === 'accepted', 409, 'Cannot undo this action.');

        $a = (int) $messageRequest->sender_id;
        $b = (int) $messageRequest->recipient_id;

        DB::transaction(function () use ($messageRequest, $a, $b) {
            Friendship::whereIn('pair_key', [$a.'-'.$b, $b.'-'.$a])->delete();
            $messageRequest->update(['status' => 'pending', 'responded_at' => null, 'seen_at' => now()]);
        });

        broadcast(new ConversationsUpdated($a));
        broadcast(new ConversationsUpdated($b));

        return response()->json(['status' => 'pending', 'id' => $messageRequest->id]);
    }
}
