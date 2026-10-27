<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_be_created_with_interactive_hidden_password_entry(): void
    {
        $password = 'A-secure-beta-password-2026';

        $this->artisan('furnicraft:admin:create')
            ->expectsQuestion('Admin name', 'Beta Admin')
            ->expectsQuestion('Admin email', 'BETA.ADMIN@example.com')
            ->expectsQuestion('Admin password (at least 12 characters)', $password)
            ->expectsQuestion('Confirm admin password', $password)
            ->expectsOutput('Administrator account created. The password was not displayed.')
            ->assertSuccessful();

        $admin = User::where('email', 'beta.admin@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check($password, $admin->password));
    }

    public function test_password_confirmation_mismatch_does_not_create_an_admin(): void
    {
        $this->artisan('furnicraft:admin:create')
            ->expectsQuestion('Admin name', 'Beta Admin')
            ->expectsQuestion('Admin email', 'beta.admin@example.com')
            ->expectsQuestion('Admin password (at least 12 characters)', 'A-secure-beta-password-2026')
            ->expectsQuestion('Confirm admin password', 'different-password-2026')
            ->expectsOutput('The password confirmation does not match. No administrator was created.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'beta.admin@example.com']);
    }
}
