<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\Note;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\ChecksChannels;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class NoteTest extends TestCase
{
    use ChecksChannels, CreatesUsers, RefreshDatabase;

    public function test_notes_are_visible_only_to_their_author_and_accepted_friends(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $eve = $this->userWithProfile('eve');

        $note = $this->actingAs($alice)->postJson('/api/notes', [
            'content' => 'Coffee at 3pm?',
        ])->assertCreated()->json();

        $this->assertSame($alice->id, $note['user']['id']);
        $this->assertSame('Coffee at 3pm?', $note['content']);

        // A stranger cannot see it at all.
        $eveNotes = $this->actingAs($eve)->getJson('/api/notes')->json();
        $this->assertCount(0, $eveNotes['data']); // Eve has no notes either
        $this->assertFalse(collect($eveNotes['data'])->contains('id', $note['id']));
        $this->actingAs($eve)->getJson("/api/notes/{$note['id']}")->assertForbidden();

        // Accepting a friend request unlocks it.
        $this->befriend($alice, $bob);
        $bobNotes = $this->actingAs($bob)->getJson('/api/notes')->json();
        $this->assertTrue(collect($bobNotes['data'])->contains('id', $note['id']));
        $this->actingAs($bob)->getJson("/api/notes/{$note['id']}")->assertOk();

        // Every place that renders Alice's avatar can show the cloud marker.
        $friends = $this->actingAs($bob)->getJson('/api/friends')->json();
        $this->assertTrue(collect($friends['friends']['data'])->firstWhere('id', $alice->id)['has_note']);

        $search = $this->actingAs($bob)->getJson('/api/users?q=alice')->json();
        $this->assertTrue(collect($search)->firstWhere('id', $alice->id)['has_note']);

        $conversationId = $this->actingAs($bob)
            ->postJson("/api/conversations/direct/{$alice->id}")->json('id');
        $conversations = $this->actingAs($bob)->getJson('/api/conversations')->json();
        $this->assertTrue(collect($conversations)->firstWhere('id', $conversationId)['has_note']);
    }

    public function test_only_owner_can_edit_or_delete_a_note(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $this->befriend($alice, $bob);

        $note = $this->actingAs($alice)->postJson('/api/notes', ['content' => 'Original'])->assertCreated()->json();

        $this->actingAs($bob)->putJson("/api/notes/{$note['id']}", ['content' => 'Hacked'])->assertForbidden();
        $this->actingAs($bob)->deleteJson("/api/notes/{$note['id']}")->assertForbidden();

        // Saving again replaces the old note instead of stacking duplicates.
        $second = $this->actingAs($alice)->postJson('/api/notes', ['content' => 'Updated'])->assertCreated()->json();
        $this->assertNotSame($note['id'], $second['id']);
        $this->assertDatabaseCount('notes', 1);

        $this->actingAs($alice)->putJson("/api/notes/{$second['id']}", ['content' => 'Edited'])
            ->assertOk()->assertJsonPath('content', 'Edited');
        $this->actingAs($alice)->deleteJson("/api/notes/{$second['id']}")->assertOk();
        $this->assertDatabaseCount('notes', 0);

        $friends = $this->actingAs($bob)->getJson('/api/friends')->json();
        $this->assertFalse(collect($friends['friends']['data'])->firstWhere('id', $alice->id)['has_note']);
    }

    public function test_expired_notes_are_hidden_even_for_the_owner(): void
    {
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

        // An expired note must not leave a cloud marker on the avatar either.
        $friends = $this->actingAs($bob)->getJson('/api/friends')->json();
        $this->assertFalse(collect($friends['friends']['data'])->firstWhere('id', $alice->id)['has_note']);
    }

    public function test_note_content_is_validated(): void
    {
        $alice = $this->userWithProfile('alice');

        $this->actingAs($alice)->postJson('/api/notes', ['content' => ''])->assertStatus(422);
        $this->actingAs($alice)->postJson('/api/notes', ['content' => str_repeat('x', 301)])->assertStatus(422);
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_note_edits_and_deletion_notify_friends(): void
    {
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
    }
}
