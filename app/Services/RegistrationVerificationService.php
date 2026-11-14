<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\RegistrationVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegistrationVerificationService
{
    public function issue(User $user, Request $request, bool $respectCooldown = false): bool
    {
        $code = (string) random_int(100000, 999999);
        $log = DB::transaction(function () use ($user, $request, $respectCooldown, $code) {
            $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($current->email_verified_at || $current->is_active) {
                return null;
            }
            if ($respectCooldown && RegistrationVerificationCode::where('user_id', $user->id)->where('created_at', '>', now()->subMinute())->exists()) {
                return null;
            }
            RegistrationVerificationCode::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            RegistrationVerificationCode::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(15),
                'requested_ip' => $request->ip(),
            ]);
            $log = NotificationLog::create([
                'user_id' => $user->id,
                'type' => 'email_verification_code',
                'recipient' => $current->email,
                'subject' => 'Verify your FurniCraft account',
                'status' => 'queued',
            ]);
            $user->setRawAttributes($current->getAttributes(), true);

            return $log;
        }, 3);
        if (! $log) {
            return false;
        }

        $user->notify(new EmailVerificationCodeNotification($code, $log->id));

        return true;
    }
}
