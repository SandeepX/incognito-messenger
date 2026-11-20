<?php

use App\Models\Note;
use App\Models\Notification;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

describe('notifications', function () {
    it('marks all notifications read and zeroes the unread count', function () {
        $alice = makeUser('alice');
        Notification::factory()->count(3)->unread()->create(['user_id' => $alice->id]);

        expect(actingAsUser($alice)->getJson('/api/notifications')->json('unread_count'))->toBe(3);

        actingAsUser($alice)->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        expect($alice->notifications()->whereNull('read_at')->count())->toBe(0);
    });

    it('decreases the unread count by exactly one when marking one read', function () {
        $alice = makeUser('alice');
        $notifications = Notification::factory()->count(3)->unread()->create(['user_id' => $alice->id]);

        actingAsUser($alice)->postJson("/api/notifications/{$notifications[0]->id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 2);

        actingAsUser($alice)->getJson('/api/notifications')->assertJsonPath('unread_count', 2);

        actingAsUser($alice)->postJson("/api/notifications/{$notifications[0]->id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 2);
    });

    it('only lists and mutates the current users notifications', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');

        $mine = Notification::factory()->unread()->create(['user_id' => $alice->id]);
        $theirs = Notification::factory()->unread()->create(['user_id' => $bob->id]);

        $page = actingAsUser($alice)->getJson('/api/notifications')->assertOk()->json();

        expect(array_column($page['data']['data'], 'id'))->toBe([$mine->id]);

        actingAsUser($alice)->postJson("/api/notifications/{$theirs->id}/read")->assertForbidden();
        expect($theirs->fresh()->read_at)->toBeNull();

        actingAsUser($alice)->postJson('/api/notifications/read-all')->assertOk();
        expect($theirs->fresh()->read_at)->toBeNull();
    });
});

describe('notes', function () {
    it('creates, edits and deletes a note', function () {
        $alice = makeUser('alice');

        $note = actingAsUser($alice)->postJson('/api/notes', ['content' => 'Coffee at 3pm?'])
            ->assertCreated()
            ->assertJsonPath('content', 'Coffee at 3pm?')
            ->assertJsonPath('user.id', $alice->id)
            ->json();

        actingAsUser($alice)->putJson("/api/notes/{$note['id']}", ['content' => 'Coffee at 4pm'])
            ->assertOk()
            ->assertJsonPath('content', 'Coffee at 4pm');

        $this->assertDatabaseHas('notes', [
            'id' => $note['id'],
            'content' => 'Coffee at 4pm',
        ]);

        actingAsUser($alice)->deleteJson("/api/notes/{$note['id']}")->assertOk();

        $this->assertDatabaseCount('notes', 0);
    });

    it('validates that note content is present and at most 60 characters', function (?string $content, bool $valid) {
        $alice = makeUser('alice');

        $response = actingAsUser($alice)->postJson('/api/notes', ['content' => $content]);

        if ($valid) {
            $response->assertCreated();
            expect(strlen($content))->toBe(60);
        } else {
            $response->assertStatus(422)->assertJsonValidationErrors('content');
            $this->assertDatabaseCount('notes', 0);
        }
    })->with([
        'missing' => [null, false],
        'empty' => ['', false],
        '61 characters' => [str_repeat('x', 61), false],
        'exactly 60 characters' => [str_repeat('x', 60), true],
    ]);

    it('is visible only to the owner and accepted friends', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $stranger = makeUser('stranger');
        makeFriends($alice, $bob);

        $note = actingAsUser($alice)->postJson('/api/notes', ['content' => 'Friends only'])
            ->assertCreated()->json();

        expect(actingAsUser($alice)->getJson('/api/notes')->json('data'))->toHaveCount(1);

        $friendNotes = actingAsUser($bob)->getJson('/api/notes')->assertOk()->json('data');
        expect(collect($friendNotes)->pluck('id'))->toContain($note['id']);

        actingAsUser($bob)->getJson("/api/notes/{$note['id']}")->assertOk();

        expect(actingAsUser($stranger)->getJson('/api/notes')->json('data'))->toBe([]);
        actingAsUser($stranger)->getJson("/api/notes/{$note['id']}")->assertForbidden();
    });

    it('only lets the owner edit or delete a note', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        makeFriends($alice, $bob);

        $note = actingAsUser($alice)->postJson('/api/notes', ['content' => 'Original'])->json();

        actingAsUser($bob)->putJson("/api/notes/{$note['id']}", ['content' => 'Hijacked'])->assertForbidden();
        actingAsUser($bob)->deleteJson("/api/notes/{$note['id']}")->assertForbidden();

        $this->assertDatabaseHas('notes', ['id' => $note['id'], 'content' => 'Original']);
    });

    it('hides notes older than 24 hours', function () {
        Carbon::setTestNow('2026-11-19 12:00:00');

        $alice = makeUser('alice');
        $bob = makeUser('bob');
        makeFriends($alice, $bob);

        $note = actingAsUser($alice)->postJson('/api/notes', ['content' => 'Temporary'])
            ->assertCreated()->json();

        expect(actingAsUser($bob)->getJson('/api/notes')->json('data'))->toHaveCount(1);

        Carbon::setTestNow('2026-11-20 12:00:01');

        expect(actingAsUser($alice)->getJson('/api/notes')->json('data'))->toBe([])
            ->and(actingAsUser($bob)->getJson('/api/notes')->json('data'))->toBe([]);

        actingAsUser($alice)->getJson("/api/notes/{$note['id']}")->assertForbidden();
        actingAsUser($bob)->getJson("/api/notes/{$note['id']}")->assertForbidden();
    });

    it('replaces the previous note instead of stacking a second one', function () {
        $alice = makeUser('alice');

        $first = actingAsUser($alice)->postJson('/api/notes', ['content' => 'First'])->json();
        $second = actingAsUser($alice)->postJson('/api/notes', ['content' => 'Second'])->json();

        expect($second['id'])->not->toBe($first['id'])
            ->and(Note::count())->toBe(1)
            ->and(Note::first()->content)->toBe('Second');

        $this->assertDatabaseMissing('notes', ['id' => $first['id']]);
    });
});
