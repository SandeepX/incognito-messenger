<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_user_profile_and_hashed_password(): void
    {
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
    }

    public function test_registration_rejects_duplicate_email_and_username(): void
    {
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
    }

    public function test_users_can_log_in_and_out(): void
    {
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
    }

    public function test_private_pages_require_authentication(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/conversations')->assertUnauthorized();
    }
}
