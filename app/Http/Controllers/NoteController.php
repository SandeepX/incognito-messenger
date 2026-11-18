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

    /** Active notes from me and my accepted friends only. */
    public function index(Request $request): JsonResponse
    {
        $me = auth()->user();
        $friendIds = $me->friendIds();
        $ids = array_merge([$me->id], $friendIds);

        $page = Note::query()
            ->active()
            ->whereIn('user_id', $ids)
            ->with('user.profile')
            ->latest()
            ->cursorPaginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Note $n) => $this->payload($n))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
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
            'content' => ['required', 'string', 'max:300'],
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

        $note->load('user.profile');

        foreach (auth()->user()->friendIds() as $friendId) {
            Notifier::send($friendId, 'note_posted', [
                'user_id' => auth()->id(),
                'name' => auth()->user()->name,
                'avatar' => auth()->user()->avatarUrl(),
                'preview' => $note->content,
            ]);
        }

        return response()->json($this->payload($note), 201);
    }

    public function update(Request $request, Note $note): JsonResponse
    {
        $this->authorize('update', $note);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:300'],
        ]);

        $note->update([
            'content' => $data['content'],
            'expires_at' => now()->addDay(),
        ]);
        $note->load('user.profile');

        return response()->json($this->payload($note));
    }

    public function destroy(Note $note): JsonResponse
    {
        $this->authorize('delete', $note);

        $note->delete();

        return response()->json(['status' => 'deleted']);
    }
}
