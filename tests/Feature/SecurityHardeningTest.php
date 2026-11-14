<?php

namespace Tests\Feature;

use App\Models\RegistrationVerificationCode;
use App\Models\User;
use App\Services\RegistrationVerificationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_change_requires_current_password_without_changing_the_account(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $originalEmail = $user->email;
        foreach ([null, 'IncorrectPassword123'] as $password) {
            $this->actingAs($user)->from(route('profile.edit'))->patch(route('profile.update'), [
                'name' => $user->name, 'email' => 'new-address@example.test', 'current_password' => $password,
            ])->assertSessionHasErrors('current_password')->assertSessionMissing('_old_input.current_password');
            $this->assertSame($originalEmail, $user->fresh()->email);
            $this->assertTrue($user->fresh()->is_active);
        }
        Notification::assertNothingSent();
    }

    public function test_normal_profile_updates_do_not_require_a_password_or_accept_a_role(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Updated Name', 'email' => $user->email, 'role' => 'admin',
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        $this->assertSame('customer', $user->fresh()->role);
        $this->assertSame('Updated Name', $user->fresh()->name);
    }

    public static function sensitiveActions(): array
    {
        return [
            ['PATCH', '/profile', 10, 60],
            ['PUT', '/profile/password', 5, 900],
            ['DELETE', '/profile/account', 3, 900],
            ['POST', '/reservations', 5, 60],
            ['PATCH', '/reservations/999999/cancel', 10, 60],
        ];
    }

    #[DataProvider('sensitiveActions')]
    public function test_sensitive_actions_limit_attempts_per_user_across_ips_and_recover(string $method, string $url, int $limit, int $seconds): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($i = 0; $i < $limit; $i++) {
            $this->json($method, $url)->assertStatus($url === '/reservations/999999/cancel' ? 404 : 422);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.77'])
            ->json($method, $url)->assertStatus(429)->assertHeader('Retry-After');
        $this->actingAs(User::factory()->create())->json($method, $url)
            ->assertStatus($url === '/reservations/999999/cancel' ? 404 : 422);
        $this->travel($seconds + 1)->seconds();
        $this->actingAs($user)->json($method, $url)
            ->assertStatus($url === '/reservations/999999/cancel' ? 404 : 422);
    }

    public function test_six_incorrect_email_codes_invalidate_the_code_even_from_a_new_ip(): void
    {
        [$user, $record] = $this->pendingVerification();
        for ($i = 0; $i < 6; $i++) {
            $this->post('/verify-email', ['email' => $user->email, 'code' => '999999'])->assertSessionHasErrors('code');
        }
        $this->assertSame(6, (int) $record->fresh()->attempts);
        $this->assertNotNull($record->fresh()->used_at);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.45'])
            ->post('/verify-email', ['email' => $user->email, 'code' => '123456'])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->is_active);
        $this->assertGuest();
    }

    public function test_email_code_is_consumed_once_and_activation_is_atomic(): void
    {
        [$user, $record] = $this->pendingVerification();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && str_starts_with(strtolower($query->sql), 'update "registration_verification_codes"')) {
                $armed = false;
                throw new \RuntimeException('Injected verification consumption failure');
            }
        });
        $this->post('/verify-email', ['email' => $user->email, 'code' => '123456'])->assertStatus(500);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($record->fresh()->used_at);
        $this->assertGuest();
        $this->post('/verify-email', ['email' => $user->email, 'code' => '123456'])->assertRedirect(route('catalog.index'));
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNotNull($record->fresh()->used_at);
        $this->post('/logout');
        $this->post('/verify-email', ['email' => $user->email, 'code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_resending_verification_enforces_cooldown_and_invalidates_the_previous_code(): void
    {
        Notification::fake();
        $this->freezeTime();
        [$user, $old] = $this->pendingVerification();
        $service = app(RegistrationVerificationService::class);
        $this->assertFalse($service->issue($user, Request::create('/'), respectCooldown: true));
        $this->travel(61)->seconds();
        $this->assertTrue($service->issue($user, Request::create('/'), respectCooldown: true));
        $this->assertFalse($service->issue($user, Request::create('/'), respectCooldown: true));
        $this->assertNotNull($old->fresh()->used_at);
        $this->assertSame(1, RegistrationVerificationCode::where('user_id', $user->id)->whereNull('used_at')->count());
        Notification::assertCount(1);
        $this->post('/verify-email', ['email' => $user->email, 'code' => '123456'])->assertSessionHasErrors('code');
    }

    private function pendingVerification(): array
    {
        $user = User::factory()->create(['is_active' => false, 'email_verified_at' => null]);
        $record = RegistrationVerificationCode::create([
            'user_id' => $user->id, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(15),
        ]);

        return [$user, $record];
    }

    public function test_password_reset_response_and_countdown_do_not_disclose_account_existence(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->create();
        $response = null;
        foreach ([$user->email, 'unknown@example.test'] as $index => $email) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 10)]);
            $this->post('/forgot-password', ['email' => $email])->assertRedirect(route('password.reset'))->assertSessionHasNoErrors()->assertSessionHas('success');
            $message = session('success');
            $response ??= $message;
            $this->assertSame($response, $message);
            $this->get('/reset-password')->assertViewHas('resendAvailableAt', now()->addMinute()->timestamp);
            $this->post('/reset-password/resend', ['email' => $email])->assertSessionHas('success', $response)->assertSessionMissing('warning');
        }
        Notification::assertCount(1);
    }

    public function test_browser_security_headers_use_a_fresh_nonce_and_preserve_inline_countdowns(): void
    {
        $first = $this->get('/login')->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security');
        $policy = $first->headers->get('Content-Security-Policy');
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $first->assertSee('nonce="'.$matches[1].'"', false);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("script-src-attr 'none'", $policy);
        $second = $this->withSession(['reset_email' => 'unknown@example.test'])->get('/reset-password')->assertOk();
        $this->assertNotSame($policy, $second->headers->get('Content-Security-Policy'));
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $second->headers->get('Content-Security-Policy'), $next);
        $second->assertSee('<script nonce="'.$next[1].'">', false);
    }

    public function test_hsts_is_sent_only_for_secure_production_requests(): void
    {
        $this->app['env'] = 'production';
        $this->get('https://furnicraft.example/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    }

    public function test_expired_email_verification_cannot_activate_an_account(): void
    {
        [$user, $record] = $this->pendingVerification();
        $record->update(['expires_at' => now()->subSecond()]);
        $this->post('/verify-email', ['email' => $user->email, 'code' => '123456'])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->is_active);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }
}
