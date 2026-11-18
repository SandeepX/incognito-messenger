<?php

namespace App\Http\Controllers;

use App\Events\AccessRevoked;
use App\Events\GroupUpdated;
use App\Events\UnreadCountsUpdated;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\User;
use App\Support\AvatarUpload;
use App\Support\ReverbConnectionTerminator;
use App\Support\UnreadCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GroupController extends Controller
{
    private function payload(Group $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'avatar' => $group->avatar_path
                ? route('avatars.show', ['filename' => basename($group->avatar_path)])
                : asset('images/default-avatar.svg'),
            'creator_id' => $group->creator_id,
            'conversation_id' => $group->conversation->id,
            'is_admin' => $group->isAdmin(auth()->id()),
            'members' => $group->members()->with('profile')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'avatar' => $u->avatarUrl(),
                'role' => $u->pivot->role,
            ]),
        ];
    }

    public function show(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return response()->json($this->payload($group->load('conversation')));
    }

    /**
     * Create the group, its conversation, and both membership records in
     * ONE transaction — a half-created group would be invisible and
     * unusable, and would break channel authorization.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'avatar' => AvatarUpload::rules(false),
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ], AvatarUpload::messages());

        $me = auth()->id();
        $memberIds = collect($data['member_ids'])
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === $me)
            ->unique()
            ->values()
            ->all();

        $avatarPath = $request->hasFile('avatar')
            ? $request->file('avatar')->store('avatars', 'local')
            : null;

        $group = DB::transaction(function () use ($data, $me, $memberIds, $avatarPath) {
            $group = Group::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'avatar_path' => $avatarPath,
                'creator_id' => $me,
            ]);

            $conversation = Conversation::create([
                'type' => 'group',
                'group_id' => $group->id,
            ]);

            $group->members()->attach($me, ['role' => 'admin']);
            foreach ($memberIds as $id) {
                $group->members()->attach($id, ['role' => 'member']);
            }
            $conversation->participants()->attach(array_merge([$me], $memberIds));

            return $group;
        });

        return response()->json([
            'id' => $group->id,
            'conversation_id' => $group->conversation->id,
        ], 201);
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        $this->authorize('manage', $group);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'avatar' => AvatarUpload::rules(false),
        ], AvatarUpload::messages());

        if ($request->hasFile('avatar')) {
            if ($group->avatar_path) {
                Storage::disk('local')->delete($group->avatar_path);
            }
            $group->avatar_path = $request->file('avatar')->store('avatars', 'local');
        }

        $group->name = $data['name'];
        $group->description = $data['description'] ?? null;
        $group->save();

        broadcast(new GroupUpdated($group->load('conversation'), 'details'));

        return response()->json($this->payload($group));
    }

    public function addMember(Request $request, Group $group): JsonResponse
    {
        $this->authorize('manage', $group);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::findOrFail($data['user_id']);

        DB::transaction(function () use ($group, $user) {
            $group->members()->syncWithoutDetaching([$user->id => ['role' => 'member']]);
            $group->conversation->participants()->syncWithoutDetaching([$user->id]);
        });

        broadcast(new GroupUpdated($group->load('conversation'), 'members'));
        broadcast(new UnreadCountsUpdated($user->id, UnreadCounter::for($user->id)));

        return response()->json($this->payload($group));
    }

    /**
     * Removing a member also removes the conversation participant row.
     * That revokes future /broadcasting/auth approvals immediately; the
     * AccessRevoked event makes an already-open browser leave the channel.
     */
    public function removeMember(Group $group, User $user): JsonResponse
    {
        $this->authorize('manage', $group);

        abort_if($group->creator_id === $user->id, 422, 'The group creator cannot be removed.');

        DB::transaction(function () use ($group, $user) {
            $group->members()->detach($user->id);
            $group->conversation->participants()->detach($user->id);
        });

        broadcast(new AccessRevoked($group->conversation->id, $user->id));
        broadcast(new GroupUpdated($group->load('conversation'), 'members'));
        broadcast(new UnreadCountsUpdated($user->id, UnreadCounter::for($user->id)));

        // Also close sockets that subscribed before the membership change.
        ReverbConnectionTerminator::forUser($user->id);

        return response()->json($this->payload($group));
    }

    public function leave(Group $group): JsonResponse
    {
        $this->authorize('leave', $group);
        $me = auth()->id();

        DB::transaction(function () use ($group, $me) {
            $group->members()->detach($me);
            $group->conversation->participants()->detach($me);
        });

        broadcast(new AccessRevoked($group->conversation->id, $me));
        broadcast(new GroupUpdated($group->load('conversation'), 'members'));

        ReverbConnectionTerminator::forUser($me);

        return response()->json(['status' => 'left']);
    }

    public function destroy(Group $group): JsonResponse
    {
        $this->authorize('manage', $group);

        $memberIds = $group->members()->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $conversationId = $group->conversation->id;

        // Tell every browser to leave before the channel disappears.
        foreach ($memberIds as $id) {
            broadcast(new AccessRevoked($conversationId, $id));
        }

        // Cascades remove the conversation, its participants, messages,
        // read receipts and membership rows.
        $group->delete();

        foreach ($memberIds as $id) {
            ReverbConnectionTerminator::forUser($id);
            broadcast(new UnreadCountsUpdated($id, UnreadCounter::for($id)));
        }

        return response()->json(['status' => 'deleted']);
    }
}
