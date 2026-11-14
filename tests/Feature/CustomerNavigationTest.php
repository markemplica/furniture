<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_usability_survey_routes_are_removed(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->get('/usability')->assertNotFound();
        $this->post('/usability')->assertNotFound();
    }

    public function test_admin_usability_results_route_is_removed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/usability-results')->assertNotFound();
    }

    public function test_usability_survey_is_absent_from_customer_and_admin_navigation(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->get(route('home'))
            ->assertOk()
            ->assertDontSee('Usability Survey');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Usability Survey');
    }
}
