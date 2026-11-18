<?php

namespace App\Http\Controllers;

use App\Events\ConversationsUpdated;
use App\Events\MessageSent;
use App\Events\MessageUnsent;
use App\Events\UnreadCountsUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageRead;
use App\Support\AttachmentUpload;
use App\Support\UnreadCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    /**
     * Send a message to a conversation the caller is allowed to use.
     * Ownership comes from auth() — never from a "from" field in the request.
     */
    public function store(Conversation $conversation, Request $request): JsonResponse
    {
        $this->authorize('send', $conversation);
        $this->ensureMessagingAllowed($conversation);

        $data = $request->validate([
            'body' => [
                'nullable', 'string', 'max:2000',
                function ($attribute, $value, $fail) use ($request) {
                    if ((! is_string($value) || trim($value) === '') && ! $request->hasFile('attachments')) {
                        $fail('Write a message or attach a file.');
                    }
                },
            ],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => AttachmentUpload::rules(),
        ], AttachmentUpload::messages());

        $files = $request->file('attachments', []);
        $files = is_array($files) ? $files : [$files];
        $stored = [];

        try {
            $message = DB::transaction(function () use ($conversation, $data, $files, &$stored) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => auth()->id(),
                    'body' => trim($data['body'] ?? ''),
                ]);

                // The sender has obviously "read" their own message.
                MessageRead::create([
                    'message_id' => $message->id,
                    'user_id' => auth()->id(),
                    'read_at' => now(),
                ]);

                foreach ($files as $file) {
                    $mime = $file->getMimeType() ?: 'application/octet-stream';
                    $type = AttachmentUpload::isImage($mime) ? 'images' : 'files';
                    $directory = "conversations/{$conversation->id}/{$type}";
                    $name = Str::uuid()->toString().'.'.($file->extension() ?: 'bin');
                    $path = $file->storeAs($directory, $name, 'minio');
                    $stored[] = $path;

                    MessageAttachment::create([
                        'message_id' => $message->id,
                        'original_filename' => AttachmentUpload::safeFilename($file->getClientOriginalName()),
                        'storage_path' => $path,
                        'mime_type' => $mime,
                        'file_size' => $file->getSize(),
                        'attachment_type' => AttachmentUpload::isImage($mime) ? 'image' : 'file',
                    ]);
                }

                return $message;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk('minio')->delete($path);
            }

            throw $e;
        }

        $message->load(['sender.profile', 'reads.user.profile', 'attachments']);
        broadcast(new MessageSent($message));

        // Push fresh badge counts to every other participant.
        $recipients = $conversation->type === 'group'
            ? $conversation->group->members()
            : $conversation->participants();

        foreach ($recipients->whereKeyNot(auth()->id())->pluck('users.id') as $userId) {
            broadcast(new UnreadCountsUpdated((int) $userId, UnreadCounter::for((int) $userId)));
        }

        return response()->json($message->payload(), 201);
    }

    /**
     * Unsend is sender-only. The server decides at request time whether the
     * message was already seen: unseen messages become a shared placeholder,
     * seen messages are hidden only from the sender's own view.
     */
    public function unsend(Message $message): JsonResponse
    {
        $me = auth()->id();

        [$message, $mode, $hiddenFor] = DB::transaction(function () use ($message, $me) {
            $locked = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();

            $this->authorize('view', $locked->conversation);
            abort_unless($locked->sender_id === $me, 403, 'Only the sender can unsend a message.');

            // Idempotent: repeated clicks (or a retried request) are harmless.
            if ($locked->is_unsent) {
                return [$locked, 'everyone', []];
            }
            if ($locked->isDeletedFor($me)) {
                return [$locked, 'local', [$me]];
            }

            $seen = $locked->reads()
                ->where('user_id', '!=', $locked->sender_id)
                ->exists();

            if ($seen) {
                $deletedFor = array_map('intval', $locked->deleted_for ?? []);
                $deletedFor[] = $me;

                $locked->deleted_for = array_values(array_unique($deletedFor));
                $locked->save();

                return [$locked, 'local', [$me]];
            }

            // Private media is removed from MinIO, never from the local disk.
            $paths = $locked->attachments()->pluck('storage_path')->all();
            if ($paths !== []) {
                Storage::disk('minio')->delete($paths);
            }
            $locked->attachments()->delete();

            $locked->forceFill([
                'body' => '',
                'is_unsent' => true,
                'unsent_at' => now(),
                'unsent_by' => $me,
            ])->save();

            return [$locked, 'everyone', []];
        });

        $message->load(['sender.profile', 'reads.user.profile']);
        $event = new MessageUnsent($message, $mode, $hiddenFor);
        broadcast($event);

        // Unsend can change badge counts and the sidebar preview for everyone.
        $conversation = $message->conversation;
        $recipientIds = $conversation->type === 'group'
            ? $conversation->group->members()->pluck('users.id')
            : $conversation->participants()->pluck('users.id');

        foreach ($recipientIds as $userId) {
            $id = (int) $userId;
            broadcast(new UnreadCountsUpdated($id, UnreadCounter::for($id)));
            // Refreshes the sidebar even for participants not in the chat.
            broadcast(new ConversationsUpdated($id));
        }

        return response()->json($event->broadcastWith());
    }

    /**
     * Direct threads may only be used by accepted friends (or a message
     * request that has been accepted, which also creates the friendship).
     */
    private function ensureMessagingAllowed(Conversation $conversation): void
    {
        if ($conversation->type !== 'direct') {
            return;
        }

        $me = auth()->user()->loadMissing('profile');
        $other = $conversation->otherParticipant((int) $me->id);
        if ($other) {
            $other->loadMissing('profile');
            abort_if($me->blocksEitherWay((int) $other->id), 403, 'You cannot message this user.');
        }

        if ($other && $me->isFriendsWith((int) $other->id)) {
            return;
        }

        $request = $conversation->messageRequest;

        if ($request?->status === 'accepted') {
            return;
        }

        abort(403, 'You cannot message this person until you are friends or they accept your message request.');
    }
}
