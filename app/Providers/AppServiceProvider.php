<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\FriendRequest;
use App\Models\Group;
use App\Models\MessageAttachment;
use App\Models\MessageRequest;
use App\Models\Note;
use App\Models\Notification;
use App\Policies\ConversationPolicy;
use App\Policies\FriendRequestPolicy;
use App\Policies\GroupPolicy;
use App\Policies\MessageAttachmentPolicy;
use App\Policies\MessageRequestPolicy;
use App\Policies\NotePolicy;
use App\Policies\NotificationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Explicit registration keeps the authorization rules obvious,
        // even if model/policy names change later.
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Group::class, GroupPolicy::class);
        Gate::policy(FriendRequest::class, FriendRequestPolicy::class);
        Gate::policy(MessageRequest::class, MessageRequestPolicy::class);
        Gate::policy(MessageAttachment::class, MessageAttachmentPolicy::class);
        Gate::policy(Note::class, NotePolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);
    }
}
