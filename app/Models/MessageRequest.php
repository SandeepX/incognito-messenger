<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id', 'recipient_id', 'conversation_id', 'pair_key',
        'body', 'status', 'seen_at', 'responded_at',
    ];

    protected $casts = [
        'seen_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
