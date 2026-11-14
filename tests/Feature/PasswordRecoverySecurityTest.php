<?php

namespace Tests\Feature;

use App\Models\PasswordResetCode;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoverySecurityTest extends TestCase
{
    use RefreshDatabase;

    private function verified(): array
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();
        $code = Notification::sent($user, PasswordResetCodeNotification::class)->first()->code;
        $this->post('/reset-password/verify', ['email' => $user->email, 'code' => $code])->assertSessionHas('password_reset_grant');

        return [$user, session('password_reset_grant')];
    }

    private function submit(User $user)
    {
        return $this->post('/reset-password', ['email' => $user->email, 'password' => 'ReplacementPassword123', 'password_confirmation' => 'ReplacementPassword123']);
    }

    public function test_replacement_code_cannot_inherit_an_older_verified_grant(): void
    {
        [$user] = $this->verified();
        $this->travel(16)->minutes();
        PasswordResetCode::create(['email' => $user->email, 'code_hash' => Hash::make('987654'), 'expires_at' => now()->addMinutes(15)]);
        $this->submit($user)->assertSessionHasErrors('code');
        $this->assertFalse(Hash::check('ReplacementPassword123', $user->fresh()->password));
    }

    public function test_expired_grant_and_legacy_email_only_grant_are_rejected(): void
    {
        [$user] = $this->verified();
        $this->travel(16)->minutes();
        $this->submit($user)->assertSessionHasErrors('code');
        PasswordResetCode::create(['email' => $user->email, 'code_hash' => Hash::make('987654'), 'expires_at' => now()->addMinutes(15)]);
        $this->withSession(['password_reset_verified_email' => $user->email]);
        $this->submit($user)->assertSessionHasErrors('code');
    }

    public function test_a_consumed_grant_cannot_be_replayed_by_a_competing_session(): void
    {
        [$user, $grant] = $this->verified();
        $this->submit($user)->assertRedirect('/login');
        $this->assertTrue(Hash::check('ReplacementPassword123', $user->fresh()->password));
        $this->withSession(['reset_email' => $user->email, 'password_reset_verified_email' => $user->email, 'password_reset_grant' => $grant]);
        $this->submit($user)->assertSessionHasErrors('code');
    }

    public function test_failure_during_code_consumption_rolls_back_the_password_change(): void
    {
        [$user] = $this->verified();
        $oldHash = $user->password;
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with(strtolower($query->sql), 'update "password_reset_codes"')) {
                throw new \RuntimeException('Injected code-consumption failure');
            }
        });
        $this->submit($user)->assertStatus(500);
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertNull(PasswordResetCode::sole()->used_at);
    }

    public function test_reset_revokes_database_sessions_and_remember_token(): void
    {
        [$user, $grant] = $this->verified();
        $other = User::factory()->create();
        foreach (['browser-one' => $user, 'browser-two' => $user, 'unrelated' => $other] as $id => $owner) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        }
        $token = $user->remember_token;
        // Exercise the configured database-session revoker without changing this test client's handler.
        config(['session.driver' => 'database']);
        $this->withSession(['reset_email' => $user->email, 'password_reset_verified_email' => $user->email, 'password_reset_grant' => $grant]);
        $this->submit($user)->assertRedirect('/login');
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated']);
        $this->assertNotSame($token, $user->fresh()->remember_token);
    }

    public function test_password_change_preserves_current_authentication_but_rejects_old_session_hash(): void
    {
        $user = User::factory()->create(['password' => 'OriginalPassword123']);
        $oldHash = $user->password;
        $this->actingAs($user)->withSession(['password_hash_web' => $oldHash])->put('/profile/password', [
            'current_password' => 'OriginalPassword123', 'password' => 'ReplacementPassword123', 'password_confirmation' => 'ReplacementPassword123',
        ])->assertRedirect('/profile');
        $this->get('/profile')->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])->get('/profile')->assertRedirectContains('/login');
        $this->assertGuest();
    }
}
