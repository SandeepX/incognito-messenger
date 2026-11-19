<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function sentFriendRequests(): HasMany
    {
        return $this->hasMany(FriendRequest::class, 'sender_id');
    }

    public function receivedFriendRequests(): HasMany
    {
        return $this->hasMany(FriendRequest::class, 'recipient_id');
    }

    public function friendships(): HasMany
    {
        return $this->hasMany(Friendship::class);
    }

    /** Accepted friends only — both directions are stored as rows. */
    public function friends(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'friendships', 'user_id', 'friend_id')
            ->withTimestamps();
    }

    public function sentMessageRequests(): HasMany
    {
        return $this->hasMany(MessageRequest::class, 'sender_id');
    }

    public function receivedMessageRequests(): HasMany
    {
        return $this->hasMany(MessageRequest::class, 'recipient_id');
    }

    public function messageReads(): HasMany
    {
        return $this->hasMany(MessageRead::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /** Active (unexpired) note, eager-loadable for list payloads. */
    public function activeNote(): HasOne
    {
        return $this->hasOne(Note::class)->active();
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(UserBlock::class, 'blocker_id');
    }

    public function blockedBy(): HasMany
    {
        return $this->hasMany(UserBlock::class, 'blocked_id');
    }

    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_blocks', 'blocker_id', 'blocked_id')
            ->withTimestamps();
    }

    public function hasBlocked(int $userId): bool
    {
        if ($userId <= 0 || $userId === (int) $this->id) {
            return false;
        }

        if ($this->relationLoaded('blocks')) {
            return $this->blocks->contains('blocked_id', $userId);
        }

        return $this->blocks()->where('blocked_id', $userId)->exists();
    }

    public function isBlockedBy(int $userId): bool
    {
        if ($userId <= 0 || $userId === (int) $this->id) {
            return false;
        }

        if ($this->relationLoaded('blockedBy')) {
            return $this->blockedBy->contains('blocker_id', $userId);
        }

        return $this->blockedBy()->where('blocker_id', $userId)->exists();
    }

    public function blocksEitherWay(int $userId): bool
    {
        return $this->hasBlocked($userId) || $this->isBlockedBy($userId);
    }

    /** @return array<int, int> */
    public function blockedUserIds(): array
    {
        return $this->blocks()->pluck('blocked_id')->map(fn ($id) => (int) $id)->all();
    }

    public function isFriendsWith(int $userId): bool
    {
        return $this->friends()->whereKey($userId)->exists();
    }

    /**
     * Friend-safe note payload for list rows. Notes are private to the author
     * and accepted friends, so a non-friend never sees even the cloud marker.
     * Also respects the author's notes visibility, blocking, and note expiry.
     */
    public function notePayload(int $viewerId): array
    {
        $note = $this->relationLoaded('activeNote')
            ? $this->activeNote
            : $this->activeNote()->first();

        $visible = $this->canShowNoteTo($viewerId, $note);

        return [
            'has_note' => $visible,
            'note' => $visible ? $note?->content : null,
        ];
    }

    public function canShowNoteTo(int $viewerId, ?Note $note = null): bool
    {
        $note ??= $this->relationLoaded('activeNote')
            ? $this->activeNote
            : $this->activeNote()->first();

        if (! $note || ! $note->expires_at?->isFuture()) {
            return false;
        }

        $viewerId = (int) $viewerId;
        if ($viewerId === (int) $this->id) {
            return true;
        }

        if ($this->blocksEitherWay($viewerId)) {
            return false;
        }

        $visibility = $this->notesVisibility();
        if ($visibility === 'nobody') {
            return false;
        }

        return $this->isFriendsWith($viewerId);
    }

    /** @return array<int, int> */
    public function friendIds(): array
    {
        return $this->friends()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    public function isFriendOfFriendWith(int $userId): bool
    {
        if ($userId <= 0 || $userId === (int) $this->id) {
            return false;
        }

        return $this->friends()
            ->whereHas('friendships', fn ($q) => $q->where('friend_id', $userId))
            ->exists();
    }

    /** Privacy flag: false means nobody else should see presence or last-active text. */
    public function showActiveStatus(): bool
    {
        return (bool) ($this->profile?->show_active_status ?? true);
    }

    public function messageSoundsEnabled(): bool
    {
        return (bool) ($this->profile?->message_sounds ?? true);
    }

    public function messageSoundsVolume(): int
    {
        $volume = (int) ($this->profile?->message_sounds_volume ?? 100);

        return max(0, min(100, $volume));
    }

    public function themePreference(): string
    {
        $theme = $this->profile?->theme_preference ?? 'system';

        return in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system';
    }

    public function desktopNotificationsEnabled(): bool
    {
        return (bool) ($this->profile?->desktop_notifications ?? false);
    }

    public function readReceiptsEnabled(): bool
    {
        return (bool) ($this->profile?->read_receipts ?? true);
    }

    public function messageRequestPolicy(): string
    {
        $policy = $this->profile?->message_request_policy ?? 'everyone';

        return in_array($policy, ['everyone', 'friends_of_friends', 'nobody'], true) ? $policy : 'everyone';
    }

    public function notesVisibility(): string
    {
        $visibility = $this->profile?->notes_visibility ?? 'friends';

        return in_array($visibility, ['friends', 'nobody'], true) ? $visibility : 'friends';
    }

    /** Who may ring this user: accepted friends only, or nobody. */
    public function callPolicy(): string
    {
        $policy = $this->profile?->allow_calls ?? 'friends';

        return in_array($policy, ['friends', 'nobody'], true) ? $policy : 'friends';
    }

    public function callRingtoneEnabled(): bool
    {
        return (bool) ($this->profile?->call_ringtone ?? true);
    }

    public function acceptsCallsFrom(User $caller): bool
    {
        if ((int) $caller->id === (int) $this->id) {
            return false;
        }

        if ($this->blocksEitherWay((int) $caller->id)) {
            return false;
        }

        return $this->callPolicy() !== 'nobody' && $this->isFriendsWith((int) $caller->id);
    }

    public function canReceiveMessageRequestFrom(User $sender): bool
    {
        if ($sender->id === $this->id) {
            return false;
        }

        if ($this->blocksEitherWay((int) $sender->id) || $this->isFriendsWith((int) $sender->id)) {
            return false;
        }

        return match ($this->messageRequestPolicy()) {
            'nobody' => false,
            'friends_of_friends' => $this->isFriendOfFriendWith((int) $sender->id),
            default => true,
        };
    }

    /** Shared shape for presence-aware payloads; hides timestamps when private. */
    public function presencePayload(): array
    {
        return $this->presencePayloadFor(null);
    }

    public function presencePayloadFor(?int $viewerId = null): array
    {
        $visible = $this->showActiveStatus();
        if ($viewerId !== null && $viewerId !== (int) $this->id && $this->blocksEitherWay($viewerId)) {
            $visible = false;
        }

        return [
            'show_active_status' => $visible,
            'last_active_at' => $visible
                ? $this->last_active_at?->toISOString()
                : null,
        ];
    }

    public function getUsernameAttribute(): string
    {
        return $this->profile?->username ?? Str::slug($this->name);
    }

    /** Avatar URL served through Laravel (storage stays private). Null when no custom photo. */
    public function avatarUrl(): ?string
    {
        return $this->profile?->avatar_path
            ? route('avatars.show', ['filename' => basename($this->profile->avatar_path)])
            : null;
    }
}
