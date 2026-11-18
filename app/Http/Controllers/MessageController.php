<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    /**
     * History of a 1:1 conversation. WebSockets are live-only, so the
     * past comes from plain HTTP the moment a conversation is opened.
     */
    public function index(User $user)
    {
        $me = auth()->id();

        return response()->json(
            Message::where(function ($q) use ($user, $me) {
                $q->where('from_user_id', $me)->where('to_user_id', $user->id);
            })->orWhere(function ($q) use ($user, $me) {
                $q->where('from_user_id', $user->id)->where('to_user_id', $me);
            })
                ->orderBy('id')
                ->limit(200)
                ->get()
                // Same shape as MessageSent::broadcastWith(), so the browser
                // can render history and live messages with ONE code path.
                ->map(fn (Message $m) => [
                    'from' => $m->from_user_id,
                    'name' => $m->from->name,
                    'to' => $m->to_user_id,
                    'body' => $m->body,
                ])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'to' => 'required|integer|exists:users,id',
            'body' => 'required|string|max:1000',
        ]);

        Message::create([
            'from_user_id' => auth()->id(),
            'to_user_id' => $data['to'],
            'body' => $data['body'],
        ]);

        MessageSent::dispatch(
            auth()->id(),
            auth()->user()->name,
            (int) $data['to'],
            $data['body'],
        );

        return response()->json(['status' => 'ok']);
    }
}
