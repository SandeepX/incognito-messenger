<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Events\UnreadCountsUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Support\UnreadCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    /**
     * Send a message to a conversation the caller is allowed to use.
     * Ownership comes from auth() — never from a "from" field in the request.
     */
    public function store(Conversation $conversation, Request $request): JsonResponse
    {
        $this->authorize('send', $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = DB::transaction(function () use ($conversation, $data) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => auth()->id(),
                'body' => $data['body'],
            ]);

            // The sender has obviously "read" their own message.
            MessageRead::create([
                'message_id' => $message->id,
                'user_id' => auth()->id(),
                'read_at' => now(),
            ]);

            return $message;
        });

        $message->load('sender.profile');
        broadcast(new MessageSent($message));

        // Push fresh badge counts to every other participant.
        $recipients = $conversation->type === 'group'
            ? $conversation->group->members()
            : $conversation->participants();

        foreach ($recipients->whereKeyNot(auth()->id())->pluck('users.id') as $userId) {
            broadcast(new UnreadCountsUpdated((int) $userId, UnreadCounter::for((int) $userId)));
        }

        return response()->json($message->payload(), 201);
    }
}
