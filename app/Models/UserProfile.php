<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'username',
        'avatar_path',
        'status',
        'bio',
        'show_active_status',
        'message_sounds',
        'theme_preference',
        'message_sounds_volume',
        'desktop_notifications',
        'read_receipts',
        'message_request_policy',
        'notes_visibility',
        'allow_calls',
        'call_ringtone',
    ];

    protected $casts = [
        'show_active_status' => 'boolean',
        'message_sounds' => 'boolean',
        'message_sounds_volume' => 'integer',
        'desktop_notifications' => 'boolean',
        'read_receipts' => 'boolean',
        'call_ringtone' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
