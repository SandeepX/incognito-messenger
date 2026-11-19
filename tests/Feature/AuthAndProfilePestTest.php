<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

describe('authentication', function () {
    it('registers a user with a profile and hashed password', function () {
        $this->post('/register', [
            'name' => 'New Person',
            'username' => 'newperson',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/');

        $this->assertAuthenticated();

        $user = User::where('email', 'new@example.com')->firstOrFail();

        expect(Hash::check('password123', $user->password))->toBeTrue()
            ->and($user->profile->username)->toBe('newperson');
    });

    it('rejects duplicate email and username registration', function () {
        makeUser('taken', ['email' => 'taken@example.com']);

        $this->post('/register', [
            'name' => 'Someone',
            'username' => 'taken',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors(['email', 'username']);

        $this->assertGuest();
    });

    it('logs a user in and out', function () {
        $user = makeUser('loginuser', [
            'email' => 'login@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->post('/login', ['email' => 'login@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'login@example.com', 'password' => 'secret123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    });

    it('redirects guests away from protected pages', function () {
        $this->get('/')->assertRedirect('/login');
        $this->get('/profile')->assertRedirect('/login');
        $this->getJson('/api/conversations')->assertUnauthorized();
        $this->getJson('/api/friends')->assertUnauthorized();
    });
});

describe('profile updates', function () {
    it('updates the profile fields', function () {
        $user = makeUser('oldname');

        actingAsUser($user)->put('/profile', [
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

    it('rejects invalid profile values', function (array $overrides, string $error) {
        $user = makeUser('alice');
        makeUser('bob');

        actingAsUser($user)->put('/profile', [
            'name' => $user->name,
            'username' => 'alice',
            'email' => $user->email,
            'bio' => null,
            'status' => null,
            ...$overrides,
        ])->assertSessionHasErrors($error);
    })->with([
        'username already taken' => [['username' => 'bob'], 'username'],
        'username too short' => [['username' => 'ab'], 'username'],
        'username has spaces' => [['username' => 'not valid'], 'username'],
        'status over 60 characters' => [['status' => str_repeat('s', 61)], 'status'],
        'bio over 160 characters' => [['bio' => str_repeat('b', 161)], 'bio'],
    ]);

    it('accepts a status of exactly 60 and a bio of exactly 160 characters', function () {
        $user = makeUser('limits');

        actingAsUser($user)->put('/profile', [
            'name' => $user->name,
            'username' => 'limits',
            'email' => $user->email,
            'status' => str_repeat('s', 60),
            'bio' => str_repeat('b', 160),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'status' => str_repeat('s', 60),
            'bio' => str_repeat('b', 160),
        ]);
    });
});

describe('avatar uploads', function () {
    it('accepts every supported avatar format', function (string $name) {
        Storage::fake('minio');
        $user = makeUser('avataruser');

        actingAsUser($user)->post('/profile/avatar', [
            'avatar' => UploadedFile::fake()->image($name, 40, 40),
        ])->assertRedirect();

        $path = $user->fresh()->profile->avatar_path;

        expect($path)->not->toBeNull();
        Storage::disk('minio')->assertExists($path);
    })->with(['avatar.jpg', 'avatar.png', 'avatar.gif', 'avatar.webp']);

    it('rejects unsupported file types and files over 10 MB', function () {
        Storage::fake('minio');
        $user = makeUser('avataruser');

        actingAsUser($user)->post('/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
        ])->assertSessionHasErrors('avatar');

        actingAsUser($user)->post('/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('huge.jpg')->size(10241),
        ])->assertSessionHasErrors('avatar');

        expect($user->fresh()->profile->avatar_path)->toBeNull();
    });

    it('accepts a phone-sized photo below the 10 MB limit', function () {
        Storage::fake('minio');
        $user = makeUser('avataruser');

        actingAsUser($user)->post('/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('phone-photo.jpg')->size(3000),
        ])->assertRedirect();

        expect($user->fresh()->profile->avatar_path)->not->toBeNull();
    });
});
