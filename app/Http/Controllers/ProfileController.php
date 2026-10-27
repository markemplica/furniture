<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileDeletionRequest;
use App\Http\Requests\ProfilePasswordUpdateRequest;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\Request;
use App\Services\PrivacyAudit;
use App\Services\RoomPhotoService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(ProfileUpdateRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        $user->update($data);

        return redirect()->route('profile.edit')->with('success', 'Your profile information has been updated.');
    }

    public function updatePassword(ProfilePasswordUpdateRequest $request)
    {
        $data = $request->validated();

        $request->user()->update(['password' => $data['password']]);
        $request->session()->regenerate();

        return redirect()->route('profile.edit')->with('success', 'Your password has been changed.');
    }

    public function destroy(ProfileDeletionRequest $request, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        $request->validated();
        $user = $request->user();
        $rooms = $user->roomImages()->get();
        foreach ($rooms as $room) $photos->deleteRoomFiles($room);
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
