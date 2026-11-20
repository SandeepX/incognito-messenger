<?php

use App\Models\Conversation;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('minio');
});

it('prunes orphaned minio objects safely', function () {
    $alice = $this->userWithProfile('alice');

    $profileAvatar = 'avatars/profile.png';
    $groupAvatar = 'avatars/group.png';
    $attachmentPath = 'conversations/1/images/used.png';

    UserProfile::where('user_id', $alice->id)->update(['avatar_path' => $profileAvatar]);
    $group = Group::create([
        'name' => 'Trip',
        'creator_id' => $alice->id,
        'avatar_path' => $groupAvatar,
    ]);
    $conversation = Conversation::create(['type' => 'group', 'group_id' => $group->id]);
    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $alice->id,
        'body' => 'Photo',
    ]);
    MessageAttachment::create([
        'message_id' => $message->id,
        'original_filename' => 'used.png',
        'storage_path' => $attachmentPath,
        'mime_type' => 'image/png',
        'file_size' => 10,
        'attachment_type' => 'image',
    ]);

    foreach ([$profileAvatar, $groupAvatar, $attachmentPath, 'avatars/orphan.png', 'conversations/1/images/orphan.png'] as $path) {
        Storage::disk('minio')->put($path, 'x');
    }

    $this->artisan('storage:prune-orphaned-files', ['--dry-run' => true])
        ->expectsOutputToContain('orphan.png');

    Storage::disk('minio')->assertExists('avatars/orphan.png');
    Storage::disk('minio')->assertExists('conversations/1/images/orphan.png');

    $this->artisan('storage:prune-orphaned-files')->assertSuccessful();

    Storage::disk('minio')->assertExists($profileAvatar);
    Storage::disk('minio')->assertExists($groupAvatar);
    Storage::disk('minio')->assertExists($attachmentPath);
    Storage::disk('minio')->assertMissing('avatars/orphan.png');
    Storage::disk('minio')->assertMissing('conversations/1/images/orphan.png');
});
