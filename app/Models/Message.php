<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = ['conversation_id', 'sender_id', 'body'];

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

    /**
     * ONE shape for HTTP history and the live broadcast event. If these
     * ever drift, messages silently disappear in the browser.
     */
    public function payload(): array
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

        $attachments = $this->relationLoaded('attachments')
            ? $this->attachments
            : $this->attachments()->get();

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'sender_name' => $this->sender->name,
            'sender_username' => $this->sender->username,
            'sender_avatar' => $this->sender->avatarUrl(),
            'body' => $this->body,
            'created_at' => $this->created_at?->toISOString(),
            'time' => $this->created_at?->format('g:i A'),
            'status' => $seenBy ? 'seen' : 'delivered',
            'seen_by' => $seenBy,
            'attachments' => $attachments->map(fn (MessageAttachment $a) => $a->payload())->all(),
        ];
    }
}
