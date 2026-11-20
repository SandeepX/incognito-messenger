<?php

namespace App\Models;

use App\Support\CallService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'type',
        'call_id',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(MessageRead::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

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

    public function payload(?int $viewerId = null, ?User $viewer = null): array
    {
        $reads = $this->relationLoaded('reads')
            ? $this->reads
            : $this->reads()->with('user.profile')->get();

        $unsent = (bool) $this->is_unsent;
        $isCall = $this->type === 'call';
        $attachments = $unsent
            ? collect()
            : ($this->relationLoaded('attachments')
                ? $this->attachments
                : $this->attachments()->get());

        $call = null;
        if ($isCall) {
            $call = $this->relationLoaded('call')
                ? $this->call
                : $this->call()->with(['caller.profile', 'callee.profile'])->first();
        }

        $preview = $unsent
            ? ($viewerId !== null && (int) $this->unsent_by === $viewerId
                ? 'You unsent a message'
                : ($this->sender?->name ?? 'Someone').' unsent a message')
            : ($isCall
                ? ($call ? CallService::messageLabel($call) : 'Audio call')
                : ($this->body ?: ($attachments->isNotEmpty()
                    ? '📎 '.$attachments->first()->original_filename
                    : '')));

        $canSeeReceipts = $viewer ? $viewer->readReceiptsEnabled() : true;
        $seenBy = [];
        if (! $unsent && ! $isCall && $canSeeReceipts) {
            $seenBy = $reads
                ->where('user_id', '!=', $this->sender_id)
                ->filter(fn (MessageRead $read) => $read->user?->readReceiptsEnabled() ?? true)
                ->map(fn (MessageRead $read) => [
                    'user_id' => (int) $read->user_id,
                    'name' => $read->user?->name,
                    'avatar' => $read->user?->avatarUrl(),
                    'read_at' => $read->read_at?->toISOString(),
                ])->values()->all();
        }

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'sender_name' => $this->sender->name,
            'sender_username' => $this->sender->username,
            'sender_avatar' => $this->sender->avatarUrl(),
            'type' => $isCall ? 'call' : 'text',
            'call' => $call ? CallService::payload($call) : null,
            'body' => $unsent || $isCall ? '' : $this->body,
            'preview' => $preview,
            'is_unsent' => $unsent,
            'unsent_at' => $this->unsent_at?->toISOString(),
            'unsent_by' => $this->unsent_by ? (int) $this->unsent_by : null,
            'created_at' => $this->created_at?->toISOString(),
            'time' => $this->created_at?->format('g:i A'),
            'status' => $unsent ? 'unsent' : ($seenBy ? 'seen' : 'sent'),
            'seen_by' => $unsent || $isCall ? [] : $seenBy,
            'attachments' => $attachments->map(fn (MessageAttachment $a) => $a->payload())->all(),
        ];
    }
}
