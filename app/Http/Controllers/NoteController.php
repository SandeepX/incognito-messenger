<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NoteController extends Controller
{
    private function payload(Note $note): array
    {
        $user = $note->user;

        return [
            'id' => $note->id,
            'user_id' => $user->id,
            'content' => $note->content,
            'created_at' => $note->created_at?->toISOString(),
            'expires_at' => $note->expires_at?->toISOString(),
            'time' => $note->created_at?->diffForHumans(),
            'is_mine' => $user->id === auth()->id(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar' => $user->avatarUrl(),
            ],
        ];
    }

    /** Active notes from me and friends who allow me to see their notes. */
    public function index(Request $request): JsonResponse
    {
        $me = auth()->user()->loadMissing('profile');
        $friendIds = $me->friendIds();
        $ids = array_merge([(int) $me->id], $friendIds);

        $notes = Note::query()
            ->active()
            ->whereIn('user_id', $ids)
            ->with(['user.profile', 'user.activeNote'])
            ->latest()
            ->get()
            ->filter(fn (Note $note) => $note->user?->canShowNoteTo((int) $me->id, $note))
            ->values();

        return response()->json([
            'data' => $notes->map(fn (Note $n) => $this->payload($n))->all(),
            'next_cursor' => null,
        ]);
    }

    public function show(Note $note): JsonResponse
    {
        $this->authorize('view', $note);
        $note->load('user.profile');

        return response()->json($this->payload($note));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:60'],
        ]);

        // One active note per person: replace it instead of stacking duplicates.
        $note = DB::transaction(function () use ($data) {
            Note::where('user_id', auth()->id())->active()->delete();

            return Note::create([
                'user_id' => auth()->id(),
                'content' => $data['content'],
                'expires_at' => now()->addDay(),
            ]);
        });

        $note->load(['user.profile', 'user.activeNote']);
        $this->notifyFriends('note_posted', $note);

        return response()->json($this->payload($note), 201);
    }

    public function update(Request $request, Note $note): JsonResponse
    {
        $this->authorize('update', $note);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:60'],
        ]);

        $note->update([
            'content' => $data['content'],
            'expires_at' => now()->addDay(),
        ]);
        $note->load(['user.profile', 'user.activeNote']);
        $this->notifyFriends('note_updated', $note);

        return response()->json($this->payload($note));
    }

    public function destroy(Note $note): JsonResponse
    {
        $this->authorize('delete', $note);

        $note->loadMissing(['user.profile', 'user.activeNote']);
        $wasVisible = true;
        $note->delete();
        $this->notifyFriends('note_deleted', $note, $wasVisible);

        return response()->json(['status' => 'deleted']);
    }

    /** Keep friends' note clouds and avatar indicators in sync in realtime. */
    private function notifyFriends(string $type, Note $note, bool $forceVisible = false): void
    {
        $user = auth()->user()->loadMissing('profile');

        foreach ($user->friendIds() as $friendId) {
            if (! $forceVisible && ! $user->canShowNoteTo((int) $friendId, $note)) {
                continue;
            }

            Notifier::send((int) $friendId, $type, [
                'user_id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar' => $user->avatarUrl(),
                'note_id' => $note->id,
                'preview' => $note->content,
            ]);
        }
    }
}
