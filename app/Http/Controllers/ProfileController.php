<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileDeletionRequest;
use App\Http\Requests\ProfilePasswordUpdateRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\PasswordResetCode;
use App\Models\RoomImage;
use App\Models\User;
use App\Services\PasswordSessionSecurity;
use App\Services\PrivacyAudit;
use App\Services\RegistrationVerificationService;
use App\Services\RoomPhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(ProfileUpdateRequest $request)
    {
        [$user, $emailChanged] = DB::transaction(function () use ($request) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $data = $request->safe()->except(['current_password']);
            $data['email'] = strtolower(trim($data['email']));
            $emailChanged = $data['email'] !== strtolower(trim($user->email));
            if ($emailChanged) {
                abort_unless(Hash::check((string) $request->input('current_password'), $user->password), 422, 'Confirm your current password before changing your email.');
                PasswordResetCode::where('email', $user->email)->whereNull('used_at')->update(['used_at' => now()]);
                $data['email_verified_at'] = null;
                $data['is_active'] = false;
                $data['remember_token'] = Str::random(60);
                app(PasswordSessionSecurity::class)->revoke($user);
            }
            $user->forceFill($data)->save();

            return [$user, $emailChanged];
        }, 3);

        if ($emailChanged) {
            app(RegistrationVerificationService::class)->issue($user, $request);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->put('registration_email', $user->email);

            return redirect()->route('verification.notice')->with('success', 'Your email address was updated. Verify the new address to continue using your account.');
        }

        return redirect()->route('profile.edit')->with('success', 'Your profile information has been updated.');
    }

    public function updatePassword(ProfilePasswordUpdateRequest $request)
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(Hash::check($data['current_password'], $user->password), 422, 'Your current password has changed. Sign in again.');
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            PasswordResetCode::where('email', $user->email)->whereNull('used_at')->update(['used_at' => now()]);
            app(PasswordSessionSecurity::class)->revoke($user, $request->session()->getId());
            $request->user()->refresh();
        });
        $request->session()->regenerate();

        return redirect()->route('profile.edit')->with('success', 'Your password has been changed.');
    }

    public function destroy(ProfileDeletionRequest $request, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        $request->validated();
        $user = $request->user();
        $rooms = $user->roomImages()->get();
        foreach ($rooms as $room) {
            DB::transaction(function () use ($photos, $room) {
                $current = RoomImage::whereKey($room->id)->lockForUpdate()->first();
                if (! $current) {
                    return;
                }
                $photos->deleteRoomFiles($current);
                $current->delete();
            });
            $audit->record('room_photo_deleted_with_account', 'room_image', $room->id, [], $user->id);
        }
        DB::table('notification_logs')->where('user_id', $user->id)->delete();
        DB::table('password_reset_codes')->where('email', $user->email)->delete();
        $audit->record('customer_account_deleted', 'user', $user->id);
        DB::table('privacy_audit_events')->where('user_id', $user->id)->update(['subject_type' => null, 'subject_id' => null, 'metadata' => null]);
        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Your account and associated personal data were deleted. Reservation and recommendation records linked to the account were also removed.');
    }
}
