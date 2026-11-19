<?php

use App\Models\Notification;

it('keeps notifications private and allows marking them read', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');

    $mine = Notification::create([
        'user_id' => $alice->id,
        'type' => 'friend_request_received',
        'data' => ['name' => 'Bob'],
    ]);
    $theirs = Notification::create([
        'user_id' => $bob->id,
        'type' => 'note_posted',
        'data' => ['name' => 'Alice'],
    ]);

    $page = $this->actingAs($alice)->getJson('/api/notifications')->assertOk()->json();
    $this->assertSame(1, $page['unread_count']);
    $this->assertCount(1, $page['data']['data']);
    $this->assertSame($mine->id, $page['data']['data'][0]['id']);
    $this->assertFalse(collect($page['data']['data'])->contains('id', $theirs->id));

    $this->actingAs($alice)->getJson("/api/notifications/{$theirs->id}")->assertNotFound();
    $this->actingAs($alice)->postJson("/api/notifications/{$theirs->id}/read")->assertForbidden();

    $this->actingAs($alice)->postJson("/api/notifications/{$mine->id}/read")
        ->assertOk()->assertJsonPath('unread_count', 0);
    $this->assertNotNull($mine->fresh()->read_at);
    $this->assertNull($theirs->fresh()->read_at);
});

it('limits mark-all-read to the current user', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');

    Notification::create(['user_id' => $alice->id, 'type' => 'note_posted', 'data' => []]);
    Notification::create(['user_id' => $bob->id, 'type' => 'note_posted', 'data' => []]);

    $this->actingAs($alice)->postJson('/api/notifications/read-all')
        ->assertOk()->assertJsonPath('unread_count', 0);

    $this->assertSame(0, $alice->notifications()->whereNull('read_at')->count());
    $this->assertSame(1, $bob->notifications()->whereNull('read_at')->count());
});
