<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginInputValidationTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidRedirects(): array
    {
        return [
            'array' => [['/catalog']],
            'nested array' => [['target' => ['/catalog']]],
            'too long' => ['/'.str_repeat('a', 2048)],
        ];
    }

    #[DataProvider('invalidRedirects')]
    public function test_login_page_rejects_malformed_redirects_without_a_server_error(mixed $redirect): void
    {
        $url = '/login?'.http_build_query(['redirect' => $redirect]);

        $this->get($url)->assertStatus(422)
            ->assertSee('The sign-in return link')
            ->assertSee('name="redirect" value=""', false);
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('redirect');
        $this->assertGuest();
    }

    #[DataProvider('invalidRedirects')]
    public function test_login_submission_rejects_malformed_redirects_before_authentication(mixed $redirect): void
    {
        $user = User::factory()->create();
        $data = ['email' => $user->email, 'password' => 'password', 'redirect' => $redirect];

        $this->post('/login', $data)->assertStatus(422)
            ->assertSee('The sign-in return link')
            ->assertSee('name="redirect" value=""', false);
        $this->assertGuest();
        $this->postJson('/login', $data)->assertUnprocessable()->assertJsonValidationErrors('redirect');
        $this->assertGuest();
    }

    public function test_oversized_and_array_credentials_are_rejected_without_flashing_passwords(): void
    {
        foreach ([
            ['email' => str_repeat('a', 245).'@example.com', 'password' => 'password', 'error' => 'email'],
            ['email' => 'test@example.com', 'password' => str_repeat('p', 1025), 'error' => 'password'],
            ['email' => ['test@example.com'], 'password' => ['password'], 'error' => 'email'],
        ] as $data) {
            $error = $data['error'];
            unset($data['error']);
            $this->postJson('/login', $data)->assertUnprocessable()->assertJsonValidationErrors($error);
            $this->post('/login', $data)->assertStatus(422)->assertSee('Sign in');
            $this->assertGuest();
            $this->assertFalse(session()->hasOldInput('password'));
        }
    }

    public function test_valid_return_link_survives_a_credential_validation_error(): void
    {
        $this->post('/login', ['email' => 'invalid', 'password' => 'password', 'redirect' => '/catalog'])
            ->assertStatus(422)->assertSee('name="redirect" value="/catalog"', false);
    }

    public function test_login_page_handles_absent_empty_valid_and_legacy_malformed_old_input(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/login?redirect=')->assertOk();
        $this->get('/login?redirect=%2Fcatalog')->assertOk()
            ->assertSee('name="redirect" value="/catalog"', false);
        $this->withSession(['_old_input' => ['email' => ['bad'], 'redirect' => ['bad']]])
            ->get('/login')->assertOk()->assertSee('name="redirect" value=""', false);
    }

    public function test_valid_login_at_the_password_limit_and_unsafe_target_fallback_still_work(): void
    {
        $password = str_repeat('p', 1024);
        $user = User::factory()->create(['password' => $password]);

        $this->post('/login', ['email' => $user->email, 'password' => $password, 'redirect' => 'https://outside.example/catalog'])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }
}
