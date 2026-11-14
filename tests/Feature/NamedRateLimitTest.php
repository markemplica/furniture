<?php

namespace Tests\Feature;

use App\Models\Recommendation;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NamedRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
    }

    public function test_catalog_search_allows_sixty_requests_per_guest_ip_and_resets_after_a_minute(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->getJson(route('catalog.search', ['q' => 'table']))->assertOk();
        }

        $this->assertLimited(
            $this->getJson(route('catalog.search', ['q' => 'chair'])),
            'You have reached the catalog search limit.', 60, 60,
        );
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->getJson(route('catalog.search', ['q' => 'table']))->assertOk();

        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->getJson(route('catalog.search', ['q' => 'table']))->assertOk();
    }

    public function test_authenticated_search_uses_the_user_across_ips_and_has_an_independent_counter(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer)->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        for ($attempt = 0; $attempt < 60; $attempt++) {
            if ($attempt === 30) {
                $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11']);
            }
            $this->getJson(route('catalog.search', ['q' => 'table']))->assertOk();
        }

        $this->assertLimited(
            $this->getJson(route('catalog.search', ['q' => 'table'])),
            'You have reached the catalog search limit.', 60, 60,
        );
        $this->post(route('recommendations.store'))->assertRedirect();
        $this->actingAs($this->customer())
            ->getJson(route('catalog.search', ['q' => 'table']))->assertOk();
    }

    public function test_recommendation_generation_is_limited_to_ten_per_user_per_minute(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post(route('recommendations.store'))->assertRedirect();
        }

        $this->assertLimited(
            $this->postJson(route('recommendations.store')),
            'You have reached the recommendation limit.', 10, 60,
        );
        $this->post(route('recommendations.store'))
            ->assertStatus(429)
            ->assertSee('You have reached the recommendation limit.')
            ->assertSee('Please try again in');
        $this->assertSame(10, $customer->recommendations()->count());

        $this->actingAs($this->customer())->post(route('recommendations.store'))->assertRedirect();
        $this->travel(61)->seconds();
        $this->actingAs($customer)->post(route('recommendations.store'))->assertRedirect();
        $this->assertSame(11, $customer->recommendations()->count());
    }

    public function test_chat_limit_is_twenty_per_user_across_recommendations_and_resets_after_a_minute(): void
    {
        $customer = $this->customer();
        $first = $this->recommendation($customer);
        $second = $this->recommendation($customer);
        $this->actingAs($customer);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $recommendation = $attempt < 10 ? $first : $second;
            $this->post(route('recommendations.chat', $recommendation), ['message' => 'What should I compare?'])
                ->assertRedirect(route('recommendations.show', $recommendation));
        }

        $this->assertDatabaseCount('chat_messages', 40);
        $this->assertLimited(
            $this->postJson(route('recommendations.chat', $second), ['message' => 'Another question']),
            'You have reached the chat message limit.', 20, 60,
        );
        $this->assertDatabaseCount('chat_messages', 40);

        $other = $this->customer();
        $otherRecommendation = $this->recommendation($other);
        $this->actingAs($other)->post(route('recommendations.chat', $otherRecommendation), ['message' => 'What should I compare?'])
            ->assertRedirect(route('recommendations.show', $otherRecommendation));

        $this->travel(61)->seconds();
        $this->actingAs($customer)->post(route('recommendations.chat', $first), ['message' => 'What should I compare?'])
            ->assertRedirect(route('recommendations.show', $first));
        $this->assertSame(11, $first->messages()->where('role', 'user')->count());
    }

    public function test_room_uploads_allow_five_per_ten_minutes_per_user_without_the_old_hourly_cap(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $customer = $this->customer();
        $this->actingAs($customer);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->uploadRoom()->assertRedirect()->assertSessionHasNoErrors();
        }

        $files = Storage::disk('local')->allFiles();
        $this->assertLimited(
            $this->postJson(route('room.store')),
            'You have reached the room upload limit.', 5, 600,
        );
        $this->assertSame(5, $customer->roomImages()->count());
        $this->assertSame($files, Storage::disk('local')->allFiles());

        $other = $this->customer();
        $this->actingAs($other);
        $this->uploadRoom()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $other->roomImages()->count());

        $this->travel(601)->seconds();
        $this->actingAs($customer);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->uploadRoom()->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->travel(601)->seconds();
        $this->uploadRoom()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(11, $customer->roomImages()->count());
    }

    public function test_existing_numeric_throttles_can_render_the_friendly_error_page(): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('feedback.store'))->assertRedirect();
        }

        $this->post(route('feedback.store'))
            ->assertStatus(429)
            ->assertSee('You have made too many requests. Please wait a moment and try again.');
    }

    public function test_per_user_limiters_preserve_authentication_requirements(): void
    {
        $this->postJson(route('recommendations.store'))->assertUnauthorized();
        $this->postJson(route('room.store'))->assertUnauthorized();
        $this->postJson(route('recommendations.chat', ['recommendation' => 1]), ['message' => 'Hello'])
            ->assertUnauthorized();
    }

    private function customer(): User
    {
        $user = User::factory()->create(['role' => 'customer']);
        $user->preference()->create(['furniture_type' => 'Table', 'budget_min' => 0, 'budget_max' => 10000]);

        return $user;
    }

    private function recommendation(User $user): Recommendation
    {
        return app(RecommendationService::class)->generate($user, $user->preference, null, 'Table');
    }

    private function uploadRoom(): TestResponse
    {
        return $this->post(route('room.store'), [
            'room_image' => UploadedFile::fake()->createWithContent('room.png', file_get_contents(base_path('tests/Fixtures/room-600.png'))),
        ]);
    }

    private function assertLimited(TestResponse $response, string $message, int $limit, int $windowSeconds): void
    {
        $response->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', (string) $limit)
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual($windowSeconds, $retryAfter);
        $response->assertJsonPath('message', $message.' Please try again in '.$retryAfter.' seconds.');
    }
}
