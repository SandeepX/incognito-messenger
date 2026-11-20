<?php

use App\Events\NotificationCreated;
use App\Models\Note;
use Illuminate\Support\Facades\Event;

it('shows notes only to their author and accepted friends', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $eve = $this->userWithProfile('eve');

    $note = $this->actingAs($alice)->postJson('/api/notes', [
        'content' => 'Coffee at 3pm?',
    ])->assertCreated()->json();

    $this->assertSame($alice->id, $note['user']['id']);
    $this->assertSame('Coffee at 3pm?', $note['content']);

    $eveNotes = $this->actingAs($eve)->getJson('/api/notes')->json();
    $this->assertCount(0, $eveNotes['data']);
    $this->assertFalse(collect($eveNotes['data'])->contains('id', $note['id']));
    $this->actingAs($eve)->getJson("/api/notes/{$note['id']}")->assertForbidden();

    $this->actingAs($alice)->postJson('/api/message-requests', [
        'user_id' => $eve->id,
        'body' => 'Hi Eve',
    ])->assertCreated();
    $requests = $this->actingAs($eve)->getJson('/api/message-requests')->json();
    $requestUser = collect($requests['incoming'])->firstWhere('user.id', $alice->id)['user'];
    $this->assertFalse($requestUser['has_note']);
    $this->assertNull($requestUser['note']);

    $eveSearch = $this->actingAs($eve)->getJson('/api/users?q=alice')->json();
    $eveRow = collect($eveSearch)->firstWhere('id', $alice->id);
    $this->assertFalse($eveRow['has_note']);
    $this->assertNull($eveRow['note']);

    $this->befriend($alice, $bob);
    $bobNotes = $this->actingAs($bob)->getJson('/api/notes')->json();
    $this->assertTrue(collect($bobNotes['data'])->contains('id', $note['id']));
    $this->actingAs($bob)->getJson("/api/notes/{$note['id']}")->assertOk();

    $friends = $this->actingAs($bob)->getJson('/api/friends')->json();
    $friendRow = collect($friends['friends'])->firstWhere('id', $alice->id);
    $this->assertTrue($friendRow['has_note']);
    $this->assertSame('Coffee at 3pm?', $friendRow['note']);

    $search = $this->actingAs($bob)->getJson('/api/users?q=alice')->json();
    $searchRow = collect($search)->firstWhere('id', $alice->id);
    $this->assertTrue($searchRow['has_note']);
    $this->assertSame('Coffee at 3pm?', $searchRow['note']);

    $conversationId = $this->actingAs($bob)
        ->postJson("/api/conversations/direct/{$alice->id}")->json('id');
    $conversations = $this->actingAs($bob)->getJson('/api/conversations')->json();
    $chatRow = collect($conversations)->firstWhere('id', $conversationId);
    $this->assertTrue($chatRow['has_note']);
    $this->assertSame('Coffee at 3pm?', $chatRow['note']);
});

it('allows only the owner to edit or delete a note', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $this->befriend($alice, $bob);

    $note = $this->actingAs($alice)->postJson('/api/notes', ['content' => 'Original'])->assertCreated()->json();

    $this->actingAs($bob)->putJson("/api/notes/{$note['id']}", ['content' => 'Hacked'])->assertForbidden();
    $this->actingAs($bob)->deleteJson("/api/notes/{$note['id']}")->assertForbidden();

    $second = $this->actingAs($alice)->postJson('/api/notes', ['content' => 'Updated'])->assertCreated()->json();
    $this->assertNotSame($note['id'], $second['id']);
    $this->assertDatabaseCount('notes', 1);

    $this->actingAs($alice)->putJson("/api/notes/{$second['id']}", ['content' => 'Edited'])
        ->assertOk()->assertJsonPath('content', 'Edited');
    $this->actingAs($alice)->deleteJson("/api/notes/{$second['id']}")->assertOk();
    $this->assertDatabaseCount('notes', 0);

    $friends = $this->actingAs($bob)->getJson('/api/friends')->json();
    $this->assertFalse(collect($friends['friends'])->firstWhere('id', $alice->id)['has_note']);
});

it('hides expired notes even for the owner', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $this->befriend($alice, $bob);

    $note = Note::create([
        'user_id' => $alice->id,
        'content' => 'Old note',
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($alice)->getJson('/api/notes')->assertOk()
        ->assertJsonMissing(['id' => $note->id]);
    $this->actingAs($alice)->getJson("/api/notes/{$note->id}")->assertForbidden();

    $friends = $this->actingAs($bob)->getJson('/api/friends')->json();
    $this->assertFalse(collect($friends['friends'])->firstWhere('id', $alice->id)['has_note']);
});

it('validates note content', function () {
    $alice = $this->userWithProfile('alice');

    $this->actingAs($alice)->postJson('/api/notes', ['content' => ''])->assertStatus(422);
    $this->actingAs($alice)->postJson('/api/notes', ['content' => str_repeat('x', 61)])->assertStatus(422);
    $this->assertDatabaseCount('notes', 0);

    $this->actingAs($alice)->postJson('/api/notes', ['content' => str_repeat('x', 60)])
        ->assertCreated()->assertJsonPath('content', str_repeat('x', 60));
    $this->assertDatabaseCount('notes', 1);
});

it('notifies friends about note edits and deletion', function () {
    Event::fake([NotificationCreated::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $this->befriend($alice, $bob);

    $note = $this->actingAs($alice)->postJson('/api/notes', ['content' => 'Original'])->assertCreated()->json();
    $this->actingAs($alice)->putJson("/api/notes/{$note['id']}", ['content' => 'Edited'])->assertOk();
    $this->actingAs($alice)->deleteJson("/api/notes/{$note['id']}")->assertOk();

    Event::assertDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $e) => $e->notification->user_id === $bob->id
            && $e->notification->type === 'note_updated',
    );
    Event::assertDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $e) => $e->notification->user_id === $bob->id
            && $e->notification->type === 'note_deleted',
    );
});
