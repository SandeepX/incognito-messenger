<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
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

    public function isFriendsWith(int $userId): bool
    {
        return $this->friends()->whereKey($userId)->exists();
    }

    /**
     * Friend-safe note payload for list rows. Notes are private to the author
     * and accepted friends, so a non-friend never sees even the cloud marker.
     */
    public function notePayload(int $viewerId): array
    {
        $note = $this->relationLoaded('activeNote')
            ? $this->activeNote
            : $this->activeNote()->first();

        $visible = $note !== null
            && ((int) $this->id === $viewerId || $this->isFriendsWith($viewerId));

        return [
            'has_note' => $visible,
            'note' => $visible ? $note->content : null,
        ];
    }

    /** @return array<int, int> */
    public function friendIds(): array
    {
        return $this->friends()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
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

    /** Shared shape for presence-aware payloads; hides timestamps when private. */
    public function presencePayload(): array
    {
        return [
            'show_active_status' => $this->showActiveStatus(),
            'last_active_at' => $this->showActiveStatus()
                ? $this->last_active_at?->toISOString()
                : null,
        ];
    }

    public function getUsernameAttribute(): string
    {
        return $this->profile?->username ?? Str::slug($this->name);
    }

    /** Avatar URL served through Laravel (storage stays private). */
    public function avatarUrl(): string
    {
        return $this->profile?->avatar_path
            ? route('avatars.show', ['filename' => basename($this->profile->avatar_path)])
            : asset('images/default-avatar.svg');
    }
}
