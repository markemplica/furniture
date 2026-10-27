<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\BetaTestAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SeederAccountSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_does_not_create_demo_accounts_or_seeded_customer_activity(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'admin@furnicraft.local']);
        $this->assertDatabaseMissing('users', ['email' => 'demo@furnicraft.local']);
        $this->assertDatabaseMissing('users', ['email' => 'neighbor@furnicraft.local']);
        $this->assertDatabaseMissing('reservations', ['reference' => 'FC-DEMO-001']);
        $this->assertSame(0, User::count());
        $this->assertGreaterThan(0, FurnitureProduct::count());
    }

    public function test_reseeding_preserves_existing_accounts_and_security_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'owner@example.test', 'password' => 'OwnerCustomPassword!', 'is_active' => false]);
        $customer = User::factory()->create(['role' => 'customer', 'email' => 'demo@furnicraft.local', 'password' => 'CustomerCustomPassword!', 'is_active' => false, 'email_verified_at' => null]);
        $adminBefore = $admin->fresh()->getAttributes();
        $customerBefore = $customer->fresh()->getAttributes();

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($adminBefore, $admin->fresh()->getAttributes());
        $this->assertSame($customerBefore, $customer->fresh()->getAttributes());
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_existing_customer_at_old_demo_admin_email_is_not_promoted_or_changed(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'email' => 'admin@furnicraft.local']);
        $before = $customer->fresh()->getAttributes();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $customer->fresh()->getAttributes());
        $this->assertSame('customer', $customer->fresh()->role);
        $this->assertSame(1, User::count());
    }

    public function test_database_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $guardTriggered = false;
        try {
            app(DatabaseSeeder::class)->run();
        } catch (RuntimeException $exception) {
            $guardTriggered = true;
            $this->assertStringContainsString('cannot be run in production', $exception->getMessage());
        }

        $this->assertTrue($guardTriggered, 'Expected the production guard to stop seeding.');
        $this->assertSame(0, User::count());
        $this->assertSame(0, FurnitureProduct::count());
    }

    public function test_test_account_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        try {
            app(BetaTestAccountsSeeder::class)->run();
            $this->fail('Production must never receive test accounts.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('never be created in production', $exception->getMessage());
        }

        $this->assertSame(0, User::count());
    }
}
