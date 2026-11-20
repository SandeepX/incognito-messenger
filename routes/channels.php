<?php

use App\Models\Call;
use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('online', function ($user) {
    return array_merge([
        'id' => $user->id,
        'name' => $user->name,
        'username' => $user->username,
        'avatar' => $user->avatarUrl(),
    ], $user->presencePayload());
});

Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conversation = Conversation::with('group')->find($id);

    if (! $conversation) {
        return false;
    }

    if ($conversation->type === 'direct') {
        return $conversation->isParticipant($user->id);
    }

    return $conversation->group?->isMember($user->id) ?? false;
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('users.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('call.{callId}', function ($user, $callId) {
    $call = Call::find($callId);

    return $call?->isParticipant((int) $user->id) ?? false;
});
