<?php

use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\Support\ChecksChannels;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full application and reset the database between
| tests. Unit tests intentionally extend plain PHPUnit so pure logic stays
| fast and framework-free.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class, CreatesUsers::class, ChecksChannels::class)
    ->in('Feature');

pest()->extend(PHPUnit\Framework\TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** Create a user with the profile every messenger screen expects. */
function makeUser(string $username, array $userAttributes = [], array $profileAttributes = []): User
{
    $user = User::factory()->create([
        'email' => $username.'@example.com',
        'name' => ucfirst($username),
        ...$userAttributes,
    ]);

    UserProfile::factory()->create([
        'user_id' => $user->id,
        'username' => $username,
        ...$profileAttributes,
    ]);

    return $user->fresh();
}

/** Authenticate the current test as the given user and return the test case. */
function actingAsUser(User $user): TestCase
{
    return test()->actingAs($user);
}

/** Make two users accepted friends (both friendship directions exist). */
function makeFriends(User $a, User $b): void
{
    Friendship::firstOrCreate(
        ['user_id' => $a->id, 'friend_id' => $b->id],
        ['pair_key' => $a->id.'-'.$b->id],
    );
    Friendship::firstOrCreate(
        ['user_id' => $b->id, 'friend_id' => $a->id],
        ['pair_key' => $b->id.'-'.$a->id],
    );

    $a->unsetRelation('friends');
    $b->unsetRelation('friends');
}

/** Create the canonical direct conversation shared by two users. */
function createConversation(User $a, User $b): Conversation
{
    $conversation = Conversation::firstOrCreate(
        ['direct_key' => Conversation::directKey((int) $a->id, (int) $b->id)],
        ['type' => 'direct'],
    );

    $conversation->participants()->syncWithoutDetaching([$a->id, $b->id]);

    return $conversation->fresh();
}

/** Invoke a real routes/channels.php callback exactly as Reverb would. */
function channelAllows(string $pattern, $user, ...$parameters): bool
{
    $channels = Broadcast::driver()->getChannels();
    $callback = $channels[$pattern] ?? null;

    test()->assertNotNull($callback, "No channel callback registered for {$pattern}");

    return (bool) $callback($user, ...$parameters);
}
