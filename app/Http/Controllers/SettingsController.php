<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\AvatarUploadRequest;
use App\Http\Requests\Profile\DeleteAccountRequest;
use App\Http\Requests\Profile\PasswordUpdateRequest;
use App\Http\Requests\Profile\ProfileUpdateRequest;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user()->loadMissing(['profile', 'blockedUsers.profile']);
        $sessions = $this->sessionsFor($request);

        return view('profile.edit', [
            'user' => $user,
            'sessions' => $sessions,
            'blockedUsers' => $user->blockedUsers->map(fn (User $blocked) => [
                'id' => $blocked->id,
                'name' => $blocked->name,
                'username' => $blocked->username,
                'avatar' => $blocked->avatarUrl(),
            ])->values(),
        ]);
    }

    public function updateProfile(ProfileUpdateRequest $request): JsonResponse|RedirectResponse
    {
        $user = $request->user()->loadMissing('profile');
        $profile = $user->profile ?: new UserProfile(['user_id' => $user->id]);

        $emailChanged = strtolower($user->email) !== strtolower($request->input('email'));

        $user->name = $request->input('name');
        $user->email = $request->input('email');
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();

        $profile->fill($request->only([
            'username',
            'bio',
            'status',
            'show_active_status',
            'read_receipts',
            'message_request_policy',
            'notes_visibility',
            'allow_calls',
        ]));
        if (! $profile->exists) {
            $profile->user_id = $user->id;
        }
        foreach ([
            'show_active_status',
            'read_receipts',
        ] as $boolean) {
            if ($request->has($boolean)) {
                $profile->{$boolean} = $request->boolean($boolean);
            }
        }
        $profile->save();

        $user->load('profile');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Profile saved.',
                'user' => $this->userPayload($user),
            ]);
        }

        return redirect()->route('profile.edit')->with('success', 'Profile updated.');
    }

    public function updateAppearance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme_preference' => ['required', Rule::in(['system', 'light', 'dark'])],
        ]);

        $profile = $this->profileFor($request->user());
        $profile->theme_preference = $data['theme_preference'];
        $profile->save();

        return response()->json(['ok' => true, 'theme_preference' => $profile->theme_preference]);
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message_sounds' => ['sometimes', 'boolean'],
            'message_sounds_volume' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'desktop_notifications' => ['sometimes', 'boolean'],
            'call_ringtone' => ['sometimes', 'boolean'],
        ]);

        $profile = $this->profileFor($request->user());
        foreach (['message_sounds', 'desktop_notifications', 'call_ringtone'] as $boolean) {
            if (array_key_exists($boolean, $data)) {
                $profile->{$boolean} = (bool) $data[$boolean];
            }
        }
        if (array_key_exists('message_sounds_volume', $data)) {
            $profile->message_sounds_volume = (int) $data['message_sounds_volume'];
        }
        $profile->save();

        return response()->json([
            'ok' => true,
            'message_sounds' => (bool) $profile->message_sounds,
            'message_sounds_volume' => (int) ($profile->message_sounds_volume ?? 100),
            'desktop_notifications' => (bool) $profile->desktop_notifications,
            'call_ringtone' => (bool) ($profile->call_ringtone ?? true),
        ]);
    }

    public function updateAvatar(AvatarUploadRequest $request): JsonResponse|RedirectResponse
    {
        $user = $request->user()->loadMissing('profile');
        $profile = $this->profileFor($user);
        $previous = $profile->avatar_path;

        $path = $request->file('avatar')->store('avatars', 'minio');
        $profile->avatar_path = $path;
        $profile->save();

        if ($previous && $previous !== $path) {
            Storage::disk('minio')->delete($previous);
        }

        $url = route('avatars.show', ['filename' => basename($path)]);
        $avatarPath = $profile->avatar_path;

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'avatar' => $url, 'avatar_path' => $avatarPath]);
        }

        return redirect()->route('profile.edit')->with('success', 'Profile picture updated.')->with('avatar', $url);
    }

    public function removeAvatar(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user()->loadMissing('profile');
        $profile = $this->profileFor($user);
        $previous = $profile->avatar_path;
        $profile->avatar_path = null;
        $profile->save();

        if ($previous) {
            Storage::disk('minio')->delete($previous);
        }

        $url = null;
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'avatar' => $url]);
        }

        return redirect()->route('profile.edit')->with('success', 'Profile picture removed.');
    }

    public function checkUsername(Request $request): JsonResponse
    {
        $username = strtolower(trim((string) $request->query('username', '')));
        $user = $request->user()->loadMissing('profile');
        $current = strtolower((string) ($user->profile?->username ?? ''));

        $validator = validator(['username' => $username], [
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:30'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'available' => false,
                'valid' => false,
                'message' => $validator->errors()->first('username'),
            ], 422);
        }

        if ($username === $current) {
            return response()->json(['available' => true, 'valid' => true, 'message' => 'This is your current username.']);
        }

        $taken = UserProfile::where('username', $username)
            ->when($user->profile?->id, fn ($q) => $q->where('id', '!=', $user->profile->id))
            ->exists();

        return response()->json([
            'available' => ! $taken,
            'valid' => true,
            'message' => $taken ? 'This username is already taken.' : 'Username is available.',
        ], $taken ? 422 : 200);
    }

    public function blockedUsers(Request $request): JsonResponse
    {
        $users = $request->user()->blockedUsers()->with('profile')->orderBy('name')->get()
            ->map(fn (User $blocked) => [
                'id' => $blocked->id,
                'name' => $blocked->name,
                'username' => $blocked->username,
                'avatar' => $blocked->avatarUrl(),
            ])->values();

        return response()->json(['data' => $users]);
    }

    public function unblock(Request $request, User $user): JsonResponse
    {
        $request->user()->blocks()->where('blocked_id', $user->id)->delete();

        return response()->json(['ok' => true]);
    }

    public function block(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->id === $user->id, 422, 'You cannot block yourself.');
        UserBlock::firstOrCreate([
            'blocker_id' => $request->user()->id,
            'blocked_id' => $user->id,
        ]);

        return response()->json(['ok' => true]);
    }

    public function updatePassword(PasswordUpdateRequest $request): JsonResponse|RedirectResponse
    {
        $request->user()->forceFill([
            'password' => Hash::make($request->input('password')),
        ])->save();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Password updated.']);
        }

        return redirect()->route('profile.edit')->with('success', 'Password updated.');
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sessionsFor($request)]);
    }

    public function destroySession(Request $request, string $session): JsonResponse
    {
        abort_if($session === $request->session()->getId(), 422, 'You cannot log out the current session here.');

        DB::table('sessions')
            ->where('id', $session)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function destroyOtherSessions(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function destroy(DeleteAccountRequest $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        DB::transaction(function () use ($user) {
            $profile = $user->profile;
            if ($profile?->avatar_path) {
                Storage::disk('minio')->delete($profile->avatar_path);
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'redirect' => route('login')]);
        }

        return redirect()->route('login')->with('status', 'Your account has been deleted.');
    }

    public function sendVerification(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            $payload = ['ok' => true, 'message' => 'Email is already verified.'];

            return $request->expectsJson() ? response()->json($payload) : back()->with('status', $payload['message']);
        }

        $user->sendEmailVerificationNotification();

        $payload = ['ok' => true, 'message' => 'Verification email sent.'];

        return $request->expectsJson() ? response()->json($payload) : back()->with('status', $payload['message']);
    }

    private function profileFor(User $user): UserProfile
    {
        $profile = $user->profile;
        if (! $profile) {
            $profile = new UserProfile(['user_id' => $user->id]);
        }
        if (! $profile->exists) {
            $profile->user_id = $user->id;
        }

        return $profile;
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('profile');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'username' => $user->username,
            'avatar' => $user->avatarUrl(),
            'status' => $user->profile?->status,
            'bio' => $user->profile?->bio,
            'show_active_status' => $user->showActiveStatus(),
            'message_sounds' => $user->messageSoundsEnabled(),
            'message_sounds_volume' => $user->messageSoundsVolume(),
            'desktop_notifications' => $user->desktopNotificationsEnabled(),
            'theme_preference' => $user->themePreference(),
            'read_receipts' => $user->readReceiptsEnabled(),
            'message_request_policy' => $user->messageRequestPolicy(),
            'notes_visibility' => $user->notesVisibility(),
            'allow_calls' => $user->callPolicy(),
            'call_ringtone' => $user->callRingtoneEnabled(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sessionsFor(Request $request): array
    {
        $current = $request->session()->getId();
        $rows = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get();

        return $rows->map(function ($row) use ($current) {
            $ua = (string) ($row->user_agent ?? '');
            $info = $this->parseUserAgent($ua);

            return [
                'id' => $row->id,
                'ip_address' => $row->ip_address,
                'is_current' => $row->id === $current,
                'last_active_at' => $row->last_activity ? now()->setTimestamp((int) $row->last_activity)->toISOString() : null,
                'last_active_human' => $row->last_activity ? now()->setTimestamp((int) $row->last_activity)->diffForHumans() : null,
                'device' => $info['device'],
                'browser' => $info['browser'],
                'platform' => $info['platform'],
            ];
        })->values()->all();
    }

    /**
     * @return array{device: string, browser: ?string, platform: ?string}
     */
    private function parseUserAgent(string $ua): array
    {
        if ($ua === '') {
            return ['device' => 'Unknown device', 'browser' => null, 'platform' => null];
        }

        $platform = 'Unknown OS';
        foreach ([
            'Windows' => '/Windows/i',
            'macOS' => '/Macintosh|Mac OS X/i',
            'iOS' => '/iPhone|iPad|iPod/i',
            'Android' => '/Android/i',
            'Linux' => '/Linux/i',
        ] as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                $platform = $name;
                break;
            }
        }

        $browser = 'Browser';
        foreach ([
            'Edge' => '/Edg\//i',
            'Opera' => '/OPR\//i',
            'Chrome' => '/Chrome\//i',
            'Firefox' => '/Firefox\//i',
            'Safari' => '/Safari\//i',
        ] as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                $browser = $name;
                break;
            }
        }

        $type = (preg_match('/Mobile|Android|iPhone/i', $ua) && ! preg_match('/iPad/i', $ua)) ? 'Mobile' : (preg_match('/iPad|Tablet/i', $ua) ? 'Tablet' : 'Desktop');

        return [
            'device' => $platform.' · '.$browser.' · '.$type,
            'browser' => $browser,
            'platform' => $platform,
        ];
    }
}
