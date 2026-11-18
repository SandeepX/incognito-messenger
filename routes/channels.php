<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Channel Authorization
|--------------------------------------------------------------------------
|
| When Echo tries to subscribe to a private or presence channel, the
| client first calls /broadcasting/auth. Laravel runs the matching rule
| below and only confirms the subscription when the callback returns
| truthy. Public channels never hit this file — they need no auth.
|
*/

// Who is online: authorized for any logged-in user, and the returned
// array is what other subscribers receive in here()/joining()/leaving().
//
// NOTE the name: Echo's join() already prepends the wire prefix, so the
// page subscribes to "presence-online" but Laravel matches on the BARE
// name "online" — /broadcasting/auth strips "presence-"/"private-" from
// the wire name before running these rules.
Broadcast::channel('online', function ($user) {
    return ['id' => $user->id, 'name' => $user->name];
});

// A 1:1 conversation channel. The two ids in the name are always in
// ascending order (sorted by both sender and listener, see MessageSent).
// You may only join a conversation you are part of.
Broadcast::channel('chat.{a}.{b}', function ($user, $a, $b) {
    return (int) $user->id === (int) $a || (int) $user->id === (int) $b;
});
