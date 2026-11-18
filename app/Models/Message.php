<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'is_unsent',
        'unsent_at',
        'unsent_by',
        'deleted_for',
    ];

    protected $casts = [
        'is_unsent' => 'boolean',
        'unsent_at' => 'datetime',
        'deleted_for' => 'array',
    ];

    public function conversation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function reads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MessageRead::class);
    }

    public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /** Messages hidden from this user's history (me-only unsend). */
    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->whereNull('deleted_for')
                ->orWhereJsonDoesntContain('deleted_for', $userId);
        });
    }

    public function isDeletedFor(int $userId): bool
    {
        return in_array($userId, array_map('intval', $this->deleted_for ?? []), true);
    }

    /**
     * ONE shape for HTTP history and the live broadcast event. If these
     * ever drift, messages silently disappear in the browser.
     *
     * The viewer id is only used to choose the "You unsent a message" wording;
     * the socket payloads pass null and the browser decides from unsent_by.
     */
    public function payload(?int $viewerId = null): array
    {
        $reads = $this->relationLoaded('reads')
            ? $this->reads
            : $this->reads()->with('user.profile')->get();

        $seenBy = $reads
            ->where('user_id', '!=', $this->sender_id)
            ->map(fn (MessageRead $read) => [
                'user_id' => (int) $read->user_id,
                'name' => $read->user?->name,
                'avatar' => $read->user?->avatarUrl(),
                'read_at' => $read->read_at?->toISOString(),
            ])->values()->all();

        $unsent = (bool) $this->is_unsent;
        $attachments = $unsent
            ? collect()
            : ($this->relationLoaded('attachments')
                ? $this->attachments
                : $this->attachments()->get());

        $preview = $unsent
            ? ($viewerId !== null && (int) $this->unsent_by === $viewerId
                ? 'You unsent a message'
                : ($this->sender?->name ?? 'Someone').' unsent a message')
            : ($this->body ?: ($attachments->isNotEmpty()
                ? '📎 '.$attachments->first()->original_filename
                : ''));

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'sender_name' => $this->sender->name,
            'sender_username' => $this->sender->username,
            'sender_avatar' => $this->sender->avatarUrl(),
            'body' => $unsent ? '' : $this->body,
            'preview' => $preview,
            'is_unsent' => $unsent,
            'unsent_at' => $this->unsent_at?->toISOString(),
            'unsent_by' => $this->unsent_by ? (int) $this->unsent_by : null,
            'created_at' => $this->created_at?->toISOString(),
            'time' => $this->created_at?->format('g:i A'),
            'status' => $unsent ? 'unsent' : ($seenBy ? 'seen' : 'delivered'),
            'seen_by' => $unsent ? [] : $seenBy,
            'attachments' => $attachments->map(fn (MessageAttachment $a) => $a->payload())->all(),
        ];
    }
}
