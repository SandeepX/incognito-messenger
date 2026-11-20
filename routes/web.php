<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageRequestController;
use App\Http\Controllers\MessengerController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\RequestSeenController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserSearchController;
use Illuminate\Support\Facades\Route;


Route::middleware('guest')->group(function () {
    Route::controller(RegisteredUserController::class)->group(function () {
        Route::get('/register', 'create')->name('register');
        Route::post('/register', 'store');
    });

    Route::controller(SessionController::class)->group(function () {
        Route::get('/login', 'create')->name('login');
        Route::post('/login', 'store')->middleware('throttle:6,1');
    });
});

Route::post('/logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/avatars/{filename}', [AvatarController::class, 'show'])
    ->where('filename', '[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|gif|webp)')
    ->name('avatars.show');

Route::middleware('auth')->group(function () {

    Route::get('/', [MessengerController::class, 'index'])->name('home');

    Route::controller(SettingsController::class)
        ->prefix('profile')
        ->name('profile.')
        ->group(function () {
            Route::get('/', 'edit')->name('edit');
            Route::put('/', 'updateProfile')->name('update');
            Route::delete('/', 'destroy')->name('destroy');
            Route::post('avatar', 'updateAvatar')->name('avatar');
            Route::delete('avatar', 'removeAvatar')->name('avatar.remove');
            Route::put('password', 'updatePassword')->name('password.update');
        });

    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::prefix('api')->group(function () {

        // Settings
        Route::controller(SettingsController::class)
            ->prefix('settings')
            ->group(function () {
                Route::get('username/check', 'checkUsername')->middleware('throttle:30,1');
                Route::post('appearance', 'updateAppearance');
                Route::post('notifications', 'updateNotifications');
                Route::match(['PUT', 'POST'], 'profile', 'updateProfile');

                Route::get('blocked-users', 'blockedUsers');
                Route::post('blocked-users/{user}', 'block');
                Route::delete('blocked-users/{user}', 'unblock');

                Route::get('sessions', 'sessions');
                Route::delete('sessions/others', 'destroyOtherSessions'); // must stay before {session}
                Route::delete('sessions/{session}', 'destroySession');
            });

        // Users
        Route::get('users', [UserSearchController::class, 'index']);

        // Conversations
        Route::controller(ConversationController::class)
            ->prefix('conversations')
            ->group(function () {
                Route::get('/', 'index');
                Route::post('direct/{user}', 'storeDirect');
                Route::get('{conversation}', 'show');
                Route::post('{conversation}/seen', 'markSeen')->middleware('throttle:240,1');
            });

        // Messages
        Route::controller(MessageController::class)->group(function () {
            Route::post('conversations/{conversation}/messages', 'store')->middleware('throttle:120,1');
            Route::post('messages/{message}/unsend', 'unsend')->middleware('throttle:60,1');
            Route::post('messages/{message}/seen', 'markSeen')->middleware('throttle:240,1');
        });

        // Presence
        Route::controller(PresenceController::class)
            ->prefix('presence')
            ->group(function () {
                Route::get('/', 'index');
                Route::post('heartbeat', 'heartbeat')->middleware('throttle:120,1');
                Route::post('disconnect', 'disconnect');
            });

        // Calls
        Route::controller(CallController::class)
            ->prefix('calls')
            ->group(function () {
                Route::get('config', 'config');
                Route::post('invite', 'invite')->middleware('throttle:6,1');

                Route::prefix('{call}')->group(function () {
                    Route::post('signal', 'signal');

                    Route::middleware('throttle:60,1')->group(function () {
                        Route::post('ringing', 'ringing');
                        Route::post('leave', 'leave');
                        Route::post('end', 'end');
                    });

                    Route::middleware('throttle:30,1')->group(function () {
                        Route::post('accept', 'accept');
                        Route::post('reject', 'reject');
                        Route::post('busy', 'busy');
                        Route::post('cancel', 'cancel');
                        Route::post('timeout', 'timeout');
                    });
                });
            });

        // Groups
        Route::apiResource('groups', GroupController::class)
            ->only(['show', 'store', 'update', 'destroy']);

        Route::controller(GroupController::class)
            ->prefix('groups/{group}')
            ->group(function () {
                Route::post('members', 'addMember');
                Route::delete('members/{user}', 'removeMember');
                Route::post('leave', 'leave');
            });

        // Friends
        Route::controller(FriendController::class)
            ->prefix('friends')
            ->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::delete('user/{user}', 'destroy');

                Route::prefix('{friend_request}')->group(function () {
                    Route::delete('/', 'cancel');
                    Route::post('accept', 'accept');
                    Route::post('reject', 'reject');
                    Route::post('undo-accept', 'undoAccept');
                    Route::post('undo-reject', 'undoReject');
                    Route::post('undo-cancel', 'undoCancel');
                });
            });

        // Message requests
        Route::controller(MessageRequestController::class)
            ->prefix('message-requests')
            ->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');

                Route::prefix('{message_request}')->group(function () {
                    Route::get('/', 'show');
                    Route::delete('/', 'cancel');
                    Route::post('accept', 'accept');
                    Route::post('delete', 'delete');
                    Route::post('block', 'block');
                    Route::post('report', 'report');
                    Route::post('undo-accept', 'undoAccept');
                    Route::post('undo-delete', 'undoDelete');
                    Route::post('undo-cancel', 'undoCancel');
                });
            });

        Route::post('requests/seen', RequestSeenController::class);

        // Notes
        Route::apiResource('notes', NoteController::class);

        // Notifications
        Route::controller(NotificationController::class)
            ->prefix('notifications')
            ->group(function () {
                Route::get('/', 'index');
                Route::post('read-all', 'markAllRead');
                Route::post('{notification}/read', 'markRead');
            });

        // Attachments
        Route::controller(AttachmentController::class)
            ->prefix('attachments/{attachment}')
            ->group(function () {
                Route::get('download', 'download')->name('attachments.download');
                Route::get('preview', 'preview')->name('attachments.preview');
            });
    });
});
