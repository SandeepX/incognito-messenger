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
        $profile->fill($request->only('username', 'bio', 'status'));
        $profile->save();

        return back()->with('status', 'Profile updated.');
    }

    /**
     * Avatar uploads are validated by MIME type AND extension, and are
     * stored with a generated filename under private storage. Only the
     * dedicated /avatars/{filename} route exposes them publicly.
     */
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

        $path = $request->file('avatar')->store('avatars', 'minio');

        if ($profile->avatar_path) {
            Storage::disk('minio')->delete($profile->avatar_path);
        }

        $profile->update(['avatar_path' => $path]);

        return back()->with('status', 'Profile picture updated.');
    }
}
