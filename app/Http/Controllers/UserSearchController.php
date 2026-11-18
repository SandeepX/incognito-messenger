<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    /** Search registered users by display name or username. */
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));
        $me = auth()->id();

        $users = User::query()
            ->with('profile')
            ->whereKeyNot($me)
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($inner) use ($query) {
                    $inner->where('name', 'like', '%'.$query.'%')
                        ->orWhereHas('profile', fn ($p) => $p->where('username', 'like', '%'.$query.'%'));
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        // One query for all existing threads instead of one per user.
        $conversationIds = Conversation::whereIn(
            'direct_key',
            $users->map(fn (User $u) => Conversation::directKey($me, $u->id)),
        )->pluck('id', 'direct_key');

        return response()->json($users->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar' => $user->avatarUrl(),
            'conversation_id' => $conversationIds[Conversation::directKey($me, $user->id)] ?? null,
        ]));
    }
}
