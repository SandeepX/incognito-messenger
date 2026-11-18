<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_profile_page_requires_authentication(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_user_can_update_profile_fields(): void
    {
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
    }

    public function test_username_and_email_must_be_unique_but_own_values_are_allowed(): void
    {
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
    }

    public function test_avatar_upload_validates_type_and_size(): void
    {
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
    }

    public function test_avatar_route_only_serves_existing_files(): void
    {
        Storage::fake('minio');
        $user = $this->userWithProfile('avataruser');

        $this->actingAs($user)->post('/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('face.png', 40, 40),
        ])->assertRedirect();

        $filename = basename($user->profile()->firstOrFail()->avatar_path);

        $this->get("/avatars/{$filename}")->assertOk();
        $this->get('/avatars/missing.jpg')->assertNotFound();
        $this->get('/avatars/..%2Fsecret.png')->assertNotFound();
    }
}
