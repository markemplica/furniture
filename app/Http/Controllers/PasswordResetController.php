<?php

namespace App\Http\Controllers;

use App\Http\Requests\PasswordResetCodeRequest;
use App\Http\Requests\PasswordResetEmailRequest;
use App\Http\Requests\PasswordResetRequest as PasswordResetFormRequest;
use App\Models\NotificationLog;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Services\PasswordSessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetController extends Controller
{
    private const CODE_MAX_FAILED_ATTEMPTS = 5;

    private const CODE_LOCKOUT_SECONDS = 900;

    private const RESET_RESPONSE = 'If that email belongs to an active account, a reset code has been sent. Check your inbox and spam folder.';

    public function showRequest()
    {
        return view('auth.forgot-password');
    }

    public function showReset(Request $request)
    {
        $email = $request->session()->get('reset_email');
        $latestCode = $email
            ? PasswordResetCode::where('email', $email)->latest('id')->first()
            : null;
        $verifiedEmail = $request->session()->get('password_reset_verified_email');
        $grant = $request->session()->get('password_reset_grant', []);
        $verified = $verifiedEmail && $verifiedEmail === $email
            && ($grant['expires_at'] ?? 0) > now()->timestamp
            && User::whereKey($grant['user_id'] ?? 0)->where('email', $email)->where('is_active', true)->exists()
            && PasswordResetCode::whereKey($grant['code_id'] ?? 0)->where('email', $email)
                ->whereNull('used_at')->where('expires_at', '>', now())
                ->where('id', $latestCode?->id)->exists();

        if ($verifiedEmail && ! $verified) {
            $request->session()->forget(['password_reset_verified_email', 'password_reset_grant']);
        }

        return view('auth.reset-password', [
            'email' => $email,
            'verified' => (bool) $verified,
            'resendAvailableAt' => $request->session()->get('password_reset_resend_at'),
        ]);
    }

    public function sendCode(PasswordResetEmailRequest $request)
    {
        $data = $request->validated();
        $email = strtolower($data['email']);
        $request->session()->put('reset_email', $email);
        $request->session()->forget(['password_reset_verified_email', 'password_reset_grant']);
        $user = User::where('email', $email)->where('is_active', true)->first();

        $request->session()->put('password_reset_resend_at', now()->addMinute()->timestamp);
        if ($user && ! $this->codeIsCoolingDown($email)) {
            $this->deliverCode($user, $request);
        }

        return redirect()->route('password.reset')->with('success', self::RESET_RESPONSE);
    }

    public function resendCode(PasswordResetEmailRequest $request)
    {
        $data = $request->validated();
        $email = strtolower($data['email']);
        $sessionEmail = strtolower((string) $request->session()->get('reset_email', ''));

        if ($sessionEmail === '' || ! hash_equals($sessionEmail, $email)) {
            return redirect()->route('password.request')
                ->withErrors(['email' => 'Request a reset code for this email first.']);
        }

        $request->session()->forget(['password_reset_verified_email', 'password_reset_grant']);

        $sessionCooldown = (int) $request->session()->get('password_reset_resend_at', 0);
        if ($sessionCooldown <= now()->timestamp) {
            $request->session()->put('password_reset_resend_at', now()->addMinute()->timestamp);
            $user = User::where('email', $email)->where('is_active', true)->first();
            if ($user && ! $this->codeIsCoolingDown($email)) {
                $this->deliverCode($user, $request);
            }
        }

        return redirect()->route('password.reset')->with('success', self::RESET_RESPONSE);
    }

    public function verifyCode(PasswordResetCodeRequest $request)
    {
        $data = $request->validated();
        $email = strtolower($data['email']);
        $sessionEmail = strtolower((string) $request->session()->get('reset_email', ''));
        $limiterKey = $this->codeAttemptLimiterKey($email);

        if (RateLimiter::tooManyAttempts($limiterKey, self::CODE_MAX_FAILED_ATTEMPTS)) {
            $request->session()->forget('password_reset_verified_email');

            return redirect()->route('password.reset')->withErrors([
                'code' => 'Too many incorrect attempts. Request a new code after the lockout period.',
            ]);
        }

        if ($sessionEmail === '' || ! hash_equals($sessionEmail, $email)) {
            $request->session()->forget('password_reset_verified_email');

            return redirect()->route('password.reset')
                ->withErrors(['code' => 'The code is incorrect or has expired. Request a new code and try again.']);
        }

        return DB::transaction(function () use ($request, $data, $email, $limiterKey) {
            $user = User::where('email', $email)->where('is_active', true)->lockForUpdate()->first();
            $record = $user ? PasswordResetCode::where('email', $email)->latest('id')->lockForUpdate()->first() : null;
            if ($record && ($record->used_at || $record->expires_at->isPast())) {
                $record = null;
            }

            if (! $record) {
                $request->session()->forget('password_reset_verified_email');

                return redirect()->route('password.reset')
                    ->withErrors(['code' => 'The code is incorrect or has expired. Request a new code and try again.']);
            }

            if (! Hash::check($data['code'], $record->code_hash)) {
                RateLimiter::hit($limiterKey, self::CODE_LOCKOUT_SECONDS);

                if (RateLimiter::tooManyAttempts($limiterKey, self::CODE_MAX_FAILED_ATTEMPTS)) {
                    $record->update(['used_at' => now()]);
                    $message = 'Too many incorrect attempts. Request a new code after the lockout period.';
                } else {
                    $message = 'The code is incorrect or has expired. Request a new code and try again.';
                }

                $request->session()->forget('password_reset_verified_email');

                return redirect()->route('password.reset')->withErrors(['code' => $message]);
            }

            RateLimiter::clear($limiterKey);
            $request->session()->put('password_reset_verified_email', $email);
            $request->session()->put('password_reset_grant', [
                'user_id' => $user->id, 'code_id' => $record->id, 'expires_at' => $record->expires_at->timestamp,
            ]);

            return redirect()->route('password.reset')->with('success', 'Code verified. You can now choose a new password.');
        });
    }

    public function reset(PasswordResetFormRequest $request)
    {
        $data = $request->validated();
        $email = strtolower($data['email']);
        $verifiedEmail = strtolower((string) $request->session()->get('password_reset_verified_email', ''));
        $grant = $request->session()->get('password_reset_grant', []);
        $changed = DB::transaction(function () use ($data, $email, $verifiedEmail, $grant) {
            $user = User::where('email', $email)->where('is_active', true)->lockForUpdate()->first();
            $record = PasswordResetCode::where('email', $email)->latest('id')->lockForUpdate()->first();
            if (! $user || ! $record || ! hash_equals($verifiedEmail, $email)
                || ($grant['user_id'] ?? null) !== $user->id || ($grant['code_id'] ?? null) !== $record->id
                || ($grant['expires_at'] ?? 0) !== $record->expires_at->timestamp
                || $record->used_at || ! $record->expires_at->isFuture()) {
                return false;
            }

            $user->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
            PasswordResetCode::where('email', $email)->whereNull('used_at')->update(['used_at' => now()]);
            app(PasswordSessionSecurity::class)->revoke($user);

            return true;
        }, 3);
        $request->session()->forget(['password_reset_verified_email', 'password_reset_grant']);
        if (! $changed) {

            return redirect()->route('password.reset')->withErrors(['code' => 'Verify a current reset code before choosing a new password.']);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Your password has been changed. You can now sign in.')->with('password_reset_completed', true);
    }

    private function codeIsCoolingDown(string $email): bool
    {
        return PasswordResetCode::where('email', $email)
            ->where('created_at', '>', now()->subSeconds(60))
            ->exists();
    }

    private function codeAttemptLimiterKey(string $email): string
    {
        return 'password-reset-code:'.hash('sha256', strtolower(trim($email)));
    }

    private function deliverCode(User $user, Request $request): bool
    {
        $code = (string) random_int(100000, 999999);
        $issued = DB::transaction(function () use ($user, $code, $request) {
            $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $current->is_active || $current->email !== $user->email || $this->codeIsCoolingDown($user->email)) {
                return null;
            }
            PasswordResetCode::where('email', $user->email)->whereNull('used_at')->update(['used_at' => now()]);
            $record = PasswordResetCode::create([
                'email' => $user->email,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(15),
                'requested_ip' => $request->ip(),
            ]);
            $log = NotificationLog::create([
                'user_id' => $user->id,
                'type' => 'password_reset_code',
                'recipient' => $user->email,
                'subject' => 'Your FurniCraft password reset code',
                'status' => 'queued',
            ]);

            return [$record, $log];
        }, 3);
        if (! $issued) {
            return false;
        }
        [$record, $log] = $issued;

        try {
            $user->notify(new PasswordResetCodeNotification($code, $log->id));
        } catch (Throwable $exception) {
            $record->update(['used_at' => now()]);
            $log->update(['status' => 'failed', 'payload' => array_merge($log->fresh()->payload ?? [], ['error_type' => class_basename($exception)])]);
            report($exception);

            return false;
        }

        return true;
    }
}
