<?php

namespace Tests\Feature;

use App\Models\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('minio');
    }

    public function test_images_and_documents_are_stored_in_minio_and_downloads_are_authorized(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $eve = $this->userWithProfile('eve');
        $this->befriend($alice, $bob);

        $conversationId = $this->actingAs($alice)
            ->postJson("/api/conversations/direct/{$bob->id}")->json('id');

        $response = $this->actingAs($alice)->post("/api/conversations/{$conversationId}/messages", [
            'body' => 'Here are the files',
            'attachments' => [
                UploadedFile::fake()->image('photo.png', 40, 40),
                UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            ],
        ])->assertCreated();

        $payload = $response->json();
        $this->assertCount(2, $payload['attachments']);
        $this->assertSame('image', $payload['attachments'][0]['attachment_type']);
        $this->assertSame('file', $payload['attachments'][1]['attachment_type']);
        $this->assertNotEmpty($payload['attachments'][0]['download_url']);

        $this->assertDatabaseCount('message_attachments', 2);
        $attachments = MessageAttachment::with('message')->get();
        foreach ($attachments as $attachment) {
            $this->assertStringStartsWith("conversations/{$conversationId}/", $attachment->storage_path);
            Storage::disk('minio')->assertExists($attachment->storage_path);
        }

        // A participant can download; an outsider cannot.
        $first = $attachments->first();
        $this->actingAs($bob)->followingRedirects()->get($first->payload()['download_url'])->assertOk();
        $this->actingAs($eve)->get($first->payload()['download_url'])->assertForbidden();

        // Previews stream from the app origin; the browser never talks to MinIO.
        $preview = $this->actingAs($bob)->get($first->payload()['preview_url']);
        $preview->assertOk();
        $this->assertSame('image/png', $preview->headers->get('Content-Type'));
        $this->assertSame('inline', $preview->headers->get('Content-Disposition'));
        $this->assertNull($preview->headers->get('Location'));
    }

    public function test_invalid_attachment_types_and_oversized_files_are_rejected(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $this->befriend($alice, $bob);
        $conversationId = $this->actingAs($alice)
            ->postJson("/api/conversations/direct/{$bob->id}")->json('id');

        $this->actingAs($alice)->post("/api/conversations/{$conversationId}/messages", [
            'attachments' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        ])->assertStatus(422)->assertJsonValidationErrors('attachments.0');

        $oversized = $this->actingAs($alice)->post("/api/conversations/{$conversationId}/messages", [
            'attachments' => [UploadedFile::fake()->create('huge.pdf', 21000, 'application/pdf')],
        ])->assertStatus(422)->assertJsonValidationErrors('attachments.0');
        $errors = $oversized->json('errors');
        $this->assertSame(
            "File can't be larger than 20 MB.",
            $errors['attachments.0'][0],
        );

        $this->assertDatabaseCount('message_attachments', 0);
        $this->assertSame(0, count(Storage::disk('minio')->allFiles()));
    }

    public function test_removed_group_member_loses_attachment_access(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');

        $group = $this->actingAs($alice)->postJson('/api/groups', [
            'name' => 'Trip',
            'member_ids' => [$bob->id],
        ])->assertCreated()->json();

        $conversationId = $group['conversation_id'];

        $this->actingAs($alice)->post("/api/conversations/{$conversationId}/messages", [
            'body' => 'Group photo',
            'attachments' => [UploadedFile::fake()->image('group.png', 30, 30)],
        ])->assertCreated();

        $attachment = MessageAttachment::firstOrFail();
        $url = $attachment->payload()['download_url'];

        $this->actingAs($bob)->followingRedirects()->get($url)->assertOk();

        $this->actingAs($alice)->deleteJson("/api/groups/{$group['id']}/members/{$bob->id}")->assertOk();

        // The object still exists, but membership is checked live on every read.
        Storage::disk('minio')->assertExists($attachment->storage_path);
        $this->actingAs($bob)->get($url)->assertForbidden();
    }
}
