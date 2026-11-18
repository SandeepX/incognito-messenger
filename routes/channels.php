<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Channel Authorization
|--------------------------------------------------------------------------
| Echo adds the wire prefix ("presence-"/"private-") itself, and Laravel
| strips it again before calling these rules. That is why the names here
| are bare: 'online', 'conversation.{id}', 'user.{id}'.
|
| These callbacks are the SECURITY BOUNDARY for real-time data. The UI
| hiding a button is never enough — every subscription is checked here.
*/

// Presence: any logged-in user may appear in the online list.
Broadcast::channel('online', function ($user) {
    return array_merge([
        'id' => $user->id,
        'name' => $user->name,
        'username' => $user->username,
        'avatar' => $user->avatarUrl(),
    ], $user->presencePayload());
});

// One private channel per conversation, shared by direct chats and groups.
Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conversation = Conversation::with('group')->find($id);

    if (! $conversation) {
        return false;
    }

    if ($conversation->type === 'direct') {
        return $conversation->isParticipant($user->id);
    }

    // Groups: membership is checked live on EVERY auth request, so
    // removing a member revokes their access to future subscriptions.
    return $conversation->group?->isMember($user->id) ?? false;
});

// Per-user channel for unread counts / account-specific notifications.
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
