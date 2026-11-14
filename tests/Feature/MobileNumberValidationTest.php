<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileNumberValidationTest extends TestCase
{
    use RefreshDatabase;

    public static function acceptedNumbers(): array
    {
        return [
            'local' => ['09171234567', '+639171234567'],
            'international' => ['+639171234567', '+639171234567'],
            'country prefix' => ['639171234567', '+639171234567'],
            'national number' => ['9171234567', '+639171234567'],
            'spaces' => ['0917 123 4567', '+639171234567'],
            'international spaces' => ['+63 917 123 4567', '+639171234567'],
            'punctuation' => ['(0917) 123-4567', '+639171234567'],
        ];
    }

    #[DataProvider('acceptedNumbers')]
    public function test_signup_and_profile_store_the_same_normalized_number(?string $input, ?string $expected): void
    {
        Notification::fake();
        $this->post('/register', $this->signup(['phone' => $input]))->assertRedirect(route('verification.notice'));
        $this->assertSame($expected, User::where('email', 'mobile-signup@example.test')->firstOrFail()->phone);
        $customer = User::factory()->create(['phone' => '+639181234567']);
        $verifiedAt = $customer->email_verified_at;
        $this->actingAs($customer)->patch('/profile', [
            'name' => $customer->name, 'email' => $customer->email, 'phone' => $input,
        ])->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();
        $this->assertSame($expected, $customer->fresh()->phone);
        $this->assertTrue($verifiedAt->equalTo($customer->fresh()->email_verified_at));
        $this->assertAuthenticatedAs($customer);
    }

    public static function invalidNumbers(): array
    {
        return [
            'blank' => [''], 'spaces only' => ['   '], 'null' => [null],
            'letters' => ['not-a-phone'], 'mixed letters' => ['0917abc4567'],
            'too short' => ['0917123456'], 'too long' => ['091712345678'],
            'landline' => ['0281234567'], 'foreign country' => ['+14155552671'],
            'extra zero' => ['+6309171234567'], 'separators only' => ['---'],
            'extension' => ['09171234567 ext 1'], 'array' => [['09171234567']],
            'numeric JSON value' => [9171234567],
            'excessive formatting' => ['0917'.str_repeat(' ', 33).'1234567'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numbers_are_rejected_before_signup_or_profile_mutation(mixed $input): void
    {
        Notification::fake();
        $this->postJson('/register', $this->signup(['phone' => $input]))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
        $customer = User::factory()->create(['phone' => '+639181234567']);
        $this->actingAs($customer)->patchJson('/profile', [
            'name' => 'Should not save', 'email' => $customer->email, 'phone' => $input,
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame('+639181234567', $customer->fresh()->phone);
        $this->assertSame($customer->name, $customer->fresh()->name);
    }

    public function test_omitted_phone_is_required_and_does_not_clear_an_existing_profile_number(): void
    {
        Notification::fake();
        $this->postJson('/register', $this->signup())->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
        $customer = User::factory()->create(['phone' => '+639181234567']);
        $this->actingAs($customer)->patchJson('/profile', ['name' => $customer->name, 'email' => $customer->email])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame('+639181234567', $customer->fresh()->phone);
    }

    public function test_array_phone_errors_can_be_rendered_after_a_browser_redirect(): void
    {
        $this->from('/register')->post('/register', $this->signup(['phone' => ['bad']]))
            ->assertRedirect('/register')->assertSessionHasErrors('phone');
        $this->get('/register')->assertOk()->assertSee('Enter a Philippine mobile number');
        $customer = User::factory()->create();
        $this->actingAs($customer)->from('/profile')->patch('/profile', [
            'name' => $customer->name, 'email' => $customer->email, 'phone' => ['bad'],
        ])->assertRedirect('/profile')->assertSessionHasErrors('phone');
        $this->get('/profile')->assertOk()->assertSee('Enter a Philippine mobile number');
    }

    private function signup(array $extra = []): array
    {
        return array_merge(['first_name' => 'Mobile', 'last_name' => 'Test', 'email' => 'mobile-signup@example.test',
            'password' => 'MobileTest12345', 'password_confirmation' => 'MobileTest12345'], $extra);
    }
}
