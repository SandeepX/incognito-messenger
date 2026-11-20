<?php

namespace App\Http\Controllers;

use App\Events\ConversationsUpdated;
use App\Events\MessageSeen;
use App\Events\MessageSent;
use App\Events\MessageUnsent;
use App\Events\UnreadCountsUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageRead;
use App\Support\AttachmentUpload;
use App\Support\UnreadCounter;
use App\Support\UnsendPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function store(Conversation $conversation, Request $request): JsonResponse
    {
        $this->authorize('send', $conversation);
        $this->ensureMessagingAllowed($conversation);

        $data = $request->validate([
            'body' => [
                'required_without:attachments', 'nullable', 'string', 'max:2000',
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

        $recipients = $conversation->type === 'group'
            ? $conversation->group->members()
            : $conversation->participants();

        foreach ($recipients->whereKeyNot(auth()->id())->pluck('users.id') as $userId) {
            broadcast(new UnreadCountsUpdated((int) $userId, UnreadCounter::for((int) $userId)));
        }

        return response()->json($message->payload(), 201);
    }

    public function unsend(Message $message, Request $request): JsonResponse
    {
        $me = (int) auth()->id();
        $mode = (string) $request->input('mode', '');
        abort_unless(
            in_array($mode, ['everyone', 'me'], true),
            422,
            'Choose whether to delete for everyone or only for yourself.'
        );

        $paths = [];
        [$message, $changed] = DB::transaction(function () use ($message, $me, $mode, &$paths) {
            $locked = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();

            $this->authorize('view', $locked->conversation);

            abort_if($locked->type === 'call', 422, 'Call history messages cannot be deleted.');

            if ($mode === 'everyone') {
                abort_unless(
                    (int) $locked->sender_id === $me,
                    403,
                    UnsendPolicy::NOT_SENDER
                );

                if ($locked->is_unsent) {
                    return [$locked, false];
                }

                $seen = $locked->reads()
                    ->where('user_id', '!=', $locked->sender_id)
                    ->exists();

                abort_unless(
                    UnsendPolicy::canDeleteForEveryone(true, $seen),
                    422,
                    UnsendPolicy::SEEN_MESSAGE
                );

                $paths = $locked->attachments()->pluck('storage_path')->all();
                $locked->attachments()->delete();

                $locked->forceFill([
                    'body' => '',
                    'is_unsent' => true,
                    'unsent_at' => now(),
                    'unsent_by' => $me,
                ])->save();

                return [$locked, true];
            }

            $deletedFor = array_values(array_unique(array_map(
                'intval',
                $locked->deleted_for ?? []
            )));

            if (in_array($me, $deletedFor, true)) {
                return [$locked, false];
            }

            $deletedFor[] = $me;
            $locked->deleted_for = $deletedFor;
            $locked->save();

            return [$locked, true];
        });

        if ($mode === 'everyone') {
            if ($paths !== []) {
                Storage::disk('minio')->delete($paths);
            }

            $message->load(['sender.profile', 'reads.user.profile']);
            $event = new MessageUnsent($message, 'everyone', []);

            if ($changed) {
                broadcast($event);

                $conversation = $message->conversation;
                $participantIds = $conversation->type === 'group'
                    ? $conversation->group->members()->pluck('users.id')
                    : $conversation->participants()->pluck('users.id');

                foreach ($participantIds as $userId) {
                    $id = (int) $userId;
                    broadcast(new UnreadCountsUpdated($id, UnreadCounter::for($id)));
                    broadcast(new ConversationsUpdated($id));
                }
            }

            return response()->json($event->broadcastWith());
        }

        $counts = UnreadCounter::for($me);
        broadcast(new UnreadCountsUpdated($me, $counts));
        broadcast(new ConversationsUpdated($me));

        return response()->json([
            'mode' => 'me',
            'changed' => $changed,
            'id' => (int) $message->id,
            'message_id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'hidden_for' => [$me],
            'unread_counts' => $counts,
        ]);
    }

    public function markSeen(Message $message): JsonResponse
    {
        $meUser = auth()->user()->loadMissing('profile');
        $me = (int) $meUser->id;

        $this->authorize('view', $message->conversation);

        abort_if((int) $message->sender_id === $me, 422, 'You cannot mark your own message as seen.');
        abort_if($message->is_unsent, 422, 'This message was unsent.');
        abort_if($message->type === 'call', 422, 'Call history messages do not have read receipts.');
        abort_if($message->isDeletedFor($me), 404);

        $read = MessageRead::firstOrCreate(
            ['message_id' => $message->id, 'user_id' => $me],
            ['read_at' => now()]
        );

        $counts = UnreadCounter::for($me);

        if ($read->wasRecentlyCreated) {
            $read->load(['user.profile', 'message']);

            if ($meUser->readReceiptsEnabled()) {
                broadcast(new MessageSeen($read, $counts));
            }
        }

        broadcast(new UnreadCountsUpdated($me, $counts));

        return response()->json([
            'status' => 'seen',
            'message_id' => (int) $message->id,
            'read_at' => $read->read_at?->toISOString(),
            'unread_counts' => $counts,
        ]);
    }

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
