<?php

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Hash;

it('creates a user profile and hashed password on registration', function () {
    $response = $this->post('/register', [
        'name' => 'New Person',
        'username' => 'newperson',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticated();

    $user = User::where('email', 'new@example.com')->firstOrFail();
    $this->assertTrue(Hash::check('password123', $user->password));
    $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'username' => 'newperson']);
});

it('rejects a duplicate email and username on registration', function () {
    $user = User::factory()->create(['email' => 'taken@example.com']);
    UserProfile::create(['user_id' => $user->id, 'username' => 'taken']);

    $this->post('/register', [
        'name' => 'Someone',
        'username' => 'taken',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors(['email', 'username']);

    $this->assertGuest();
});

it('allows users to log in and out', function () {
    $user = User::factory()->create([
        'email' => 'login@example.com',
        'password' => Hash::make('secret123'),
    ]);
    UserProfile::create(['user_id' => $user->id, 'username' => 'loginuser']);

    $this->post('/login', ['email' => 'login@example.com', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post('/login', ['email' => 'login@example.com', 'password' => 'secret123'])
        ->assertRedirect('/');
    $this->assertAuthenticatedAs($user);

    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

it('requires authentication for private pages', function () {
    $this->get('/')->assertRedirect('/login');
    $this->getJson('/api/conversations')->assertUnauthorized();
});
