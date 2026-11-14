<?php

namespace Tests\Feature;

use App\Models\RegistrationVerificationCode;
use App\Models\User;
use App\Services\RegistrationVerificationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationVerificationRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_deletes_only_codes_inactive_for_the_retention_period(): void
    {
        $this->freezeTime();
        config(['privacy.registration_verification_retention_hours' => 24]);
        $user = User::factory()->create(['email_verified_at' => null, 'is_active' => false]);
        $oldExpired = $this->code($user, ['expires_at' => now()->subHours(25)]);
        $oldUsed = $this->code($user, ['expires_at' => now()->addHour(), 'used_at' => now()->subHours(25)]);
        $boundary = $this->code($user, ['expires_at' => now()->subHours(24)]);
        $recentExpired = $this->code($user, ['expires_at' => now()->subHours(23)]);
        $recentUsed = $this->code($user, ['used_at' => now()->subHour()]);
        $active = $this->code($user);

        $this->artisan('registration-codes:prune')->expectsOutput('Deleted registration verification records: 3.')->assertSuccessful();
        foreach ([$oldExpired, $oldUsed, $boundary] as $code) $this->assertModelMissing($code);
        foreach ([$recentExpired, $recentUsed, $active] as $code) $this->assertModelExists($code);
        $this->assertModelExists($user);
        $this->post('/verify-email', ['email' => $user->email, 'code' => '123456'])->assertRedirect(route('catalog.index'));
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_dry_run_does_not_modify_records_and_repeat_cleanup_is_safe(): void
    {
        $this->freezeTime();
        config(['privacy.registration_verification_retention_hours' => 24]);
        $code = $this->code(User::factory()->create(), ['expires_at' => now()->subHours(25)]);
        $before = $code->fresh()->getAttributes();
        $this->artisan('registration-codes:prune --dry-run')
            ->expectsOutput('Eligible registration verification records: 1. No records were deleted.')->assertSuccessful();
        $this->assertSame($before, $code->fresh()->getAttributes());
        $this->artisan('registration-codes:prune')->assertSuccessful();
        $this->artisan('registration-codes:prune')->expectsOutput('Deleted registration verification records: 0.')->assertSuccessful();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_configured_retention_is_used_with_a_one_hour_minimum(): void
    {
        $this->freezeTime();
        config(['privacy.registration_verification_retention_hours' => 48]);
        $user = User::factory()->create();
        $keep = $this->code($user, ['expires_at' => now()->subHours(25)]);
        $remove = $this->code($user, ['expires_at' => now()->subHours(49)]);
        $this->artisan('registration-codes:prune')->assertSuccessful();
        $this->assertModelExists($keep);
        $this->assertModelMissing($remove);
        config(['privacy.registration_verification_retention_hours' => 0]);
        $recent = $this->code($user, ['expires_at' => now()->subMinutes(30)]);
        $this->artisan('registration-codes:prune')->assertSuccessful();
        $this->assertModelExists($recent);
    }

    public function test_cleanup_handles_multiple_batches_without_deleting_valid_codes(): void
    {
        $this->freezeTime();
        config(['privacy.registration_verification_retention_hours' => 24]);
        $user = User::factory()->create();
        $hash = Hash::make('123456');
        $record = ['user_id' => $user->id, 'code_hash' => $hash, 'expires_at' => now()->subHours(25),
            'used_at' => null, 'attempts' => 0, 'requested_ip' => '192.0.2.1', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)];
        foreach (array_chunk(array_fill(0, 1005, $record), 200) as $batch) DB::table('registration_verification_codes')->insert($batch);
        $valid = $this->code($user);
        $this->artisan('registration-codes:prune')->expectsOutput('Deleted registration verification records: 1005.')->assertSuccessful();
        $this->assertDatabaseCount('registration_verification_codes', 1);
        $this->assertModelExists($valid);
    }

    public function test_cleanup_preserves_the_latest_code_and_resend_cooldown(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->create(['email_verified_at' => null, 'is_active' => false]);
        $this->code($user, ['expires_at' => now()->subDays(2), 'used_at' => now()->subDays(2)]);
        $latest = $this->code($user);
        $this->artisan('registration-codes:prune')->assertSuccessful();
        $this->assertFalse(app(RegistrationVerificationService::class)->issue($user, Request::create('/'), respectCooldown: true));
        $this->assertModelExists($latest);
        Notification::assertNothingSent();
    }

    public function test_hourly_schedule_and_notice_describe_the_configured_policy(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'registration-codes:prune'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        config(['privacy.registration_verification_retention_hours' => 48]);
        $this->get(route('privacy-policy'))->assertOk()->assertSee('expired for at least 48 hours')
            ->assertSee('not your account')->assertSee('next successful cleanup');
    }

    private function code(User $user, array $attributes = []): RegistrationVerificationCode
    {
        return RegistrationVerificationCode::create(array_merge(['user_id' => $user->id, 'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15), 'requested_ip' => '192.0.2.1'], $attributes));
    }
}
