<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('requires authentication for the profile page', function () {
    $this->get('/profile')->assertRedirect('/login');
});

it('allows a user to update profile fields', function () {
    $user = $this->userWithProfile('oldname');

    $this->actingAs($user)->put('/profile', [
        'name' => 'New Name',
        'username' => 'newname',
        'email' => 'new@example.com',
        'bio' => 'Hello there',
        'status' => 'Available',
    ])->assertRedirect();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'New Name',
        'email' => 'new@example.com',
    ]);
    $this->assertDatabaseHas('user_profiles', [
        'user_id' => $user->id,
        'username' => 'newname',
        'bio' => 'Hello there',
        'status' => 'Available',
    ]);
});

it('persists message sound and active status settings', function () {
    $user = $this->userWithProfile('settings');

    $this->assertDatabaseHas('user_profiles', [
        'user_id' => $user->id,
        'message_sounds' => 1,
        'show_active_status' => 1,
    ]);

    $this->actingAs($user)->put('/profile', [
        'name' => $user->name,
        'username' => 'settings',
        'email' => $user->email,
        'show_active_status' => '0',
    ])->assertRedirect();

    $this->assertDatabaseHas('user_profiles', [
        'user_id' => $user->id,
        'message_sounds' => 1,
        'show_active_status' => 0,
    ]);

    // Message sounds live on the dedicated notification-settings endpoint.
    $this->actingAs($user)->postJson('/api/settings/notifications', [
        'message_sounds' => false,
    ])->assertOk()->assertJsonPath('message_sounds', false);

    $this->assertDatabaseHas('user_profiles', [
        'user_id' => $user->id,
        'message_sounds' => 0,
        'show_active_status' => 0,
    ]);

    $this->actingAs($user)->postJson('/api/settings/notifications', [
        'message_sounds' => true,
    ])->assertOk();

    $this->assertDatabaseHas('user_profiles', [
        'user_id' => $user->id,
        'message_sounds' => 1,
        'show_active_status' => 0,
    ]);
});

it('requires unique username and email but allows the user to keep their own values', function () {
    $user = $this->userWithProfile('alice');
    $this->userWithProfile('bob');

    // Saving your own unchanged values must succeed.
    $this->actingAs($user)->put('/profile', [
        'name' => $user->name,
        'username' => 'alice',
        'email' => 'alice@example.com',
    ])->assertSessionHasNoErrors();

    // Taking someone else's username/email must fail.
    $this->actingAs($user)->put('/profile', [
        'name' => $user->name,
        'username' => 'bob',
        'email' => 'bob@example.com',
    ])->assertSessionHasErrors(['username', 'email']);
});

it('validates avatar upload type and size', function () {
    Storage::fake('minio');
    $user = $this->userWithProfile('avataruser');

    $this->actingAs($user)->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
    ])->assertSessionHasErrors('avatar');

    $this->actingAs($user)->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('huge.jpg')->size(11000),
    ])->assertSessionHasErrors('avatar');

    // A typical phone photo (a few MB) must be accepted; the old 2 MB
    // cap rejected these before Laravel could validate them.
    $this->actingAs($user)->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('phone-photo.jpg')->size(3000),
    ])->assertRedirect();

    $this->actingAs($user)->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('me.jpg', 60, 60),
    ])->assertRedirect();

    $profile = $user->profile()->firstOrFail();
    $this->assertNotNull($profile->avatar_path);
    Storage::disk('minio')->assertExists($profile->avatar_path);
});

it('only serves existing files from the avatar route', function () {
    Storage::fake('minio');
    $user = $this->userWithProfile('avataruser');

    $this->actingAs($user)->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('face.png', 40, 40),
    ])->assertRedirect();

    $filename = basename($user->profile()->firstOrFail()->avatar_path);

    $this->get("/avatars/{$filename}")->assertOk();
    $this->get('/avatars/missing.jpg')->assertNotFound();
    $this->get('/avatars/..%2Fsecret.png')->assertNotFound();
});
