<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\Group;
use App\Policies\ConversationPolicy;
use App\Policies\GroupPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Explicit registration keeps the authorization rules obvious,
        // even if model/policy names change later.
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Group::class, GroupPolicy::class);
    }
}
