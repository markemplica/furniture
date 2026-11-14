<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiUsagePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_page_is_admin_only(): void
    {
        $this->get(route('admin.api-usage'))->assertRedirectContains('/login');
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get(route('admin.api-usage'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.api-usage'))
            ->assertOk()->assertSee('API usage &amp; budget controls', false)
            ->assertSee('Provider attempts today')->assertSee('Back to overview');
    }

    public function test_overview_links_to_usage_without_rendering_the_budget_panel(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.dashboard'))
            ->assertOk()->assertSee(route('admin.api-usage'))
            ->assertDontSee('id="api-usage-heading"', false)->assertDontSee('Provider attempts today');
    }
}
