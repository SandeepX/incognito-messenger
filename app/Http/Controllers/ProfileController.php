<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\ProfileUpdateRequest;
use App\Models\UserProfile;
use App\Support\AvatarUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('profile.edit', [
            'user' => auth()->user()->loadMissing('profile'),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->name = $request->input('name');
        $user->email = $request->input('email');
        $user->save();

        $profile = $user->profile ?: new UserProfile(['user_id' => $user->id]);
        $profile->fill($request->only(
            'username',
            'bio',
            'status',
            'message_sounds',
            'show_active_status',
        ));

        $profile->message_sounds = $request->boolean('message_sounds', true);
        $profile->show_active_status = $request->boolean('show_active_status', true);
        $profile->save();

        return back()->with('status', 'Profile updated.');
    }

    public function updateAvatar(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            ['avatar' => AvatarUpload::rules()],
            AvatarUpload::messages(),
        );

        $profile = $request->user()->profile ?: UserProfile::create([
            'user_id' => $request->user()->id,
            'username' => $request->user()->username,
        ]);

        $oldPath = $profile->avatar_path;
        $path = $request->file('avatar')->store('avatars', 'minio');

        try {
            $profile->update(['avatar_path' => $path]);
        } catch (\Throwable $e) {
            Storage::disk('minio')->delete($path);
            throw $e;
        }

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('minio')->delete($oldPath);
        }

        return back()->with('status', 'Profile picture updated.');
    }
}
