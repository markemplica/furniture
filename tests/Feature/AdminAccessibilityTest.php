<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\Reservation;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_reservations_row_spans_all_table_columns(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('admin.reservations.index'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $columnCount = $xpath->query('//main//table/thead/tr/th')->length;
        $emptyCell = $xpath->query('//main//table/tbody/tr/td')->item(0);

        $this->assertGreaterThan(0, $columnCount);
        $this->assertSame($columnCount, (int) $emptyCell->getAttribute('colspan'));
    }

    public function test_admin_catalog_and_reservation_controls_have_accessible_names(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $product = FurnitureProduct::create([
            'name' => 'Accessible Table', 'slug' => 'accessible-table',
            'category' => 'Dining Table', 'description' => 'Wooden table',
            'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural',
            'style' => 'Modern', 'price' => 1000, 'status' => 'active',
        ]);
        Reservation::create([
            'reference' => 'FC-ACCESSIBILITY', 'user_id' => $customer->id,
            'furniture_product_id' => $product->id, 'branch' => 'Lingayen',
            'status' => 'confirmed',
        ]);

        foreach (['admin.reservations.index', 'admin.products.index'] as $route) {
            $response = $this->actingAs($admin)->get(route($route))->assertOk();
            $xpath = $this->xpath($response->getContent());
            $controls = $xpath->query('//main//input[not(@type="hidden")] | //main//select | //main//textarea | //main//button');

            $this->assertGreaterThan(0, $controls->length);
            foreach ($controls as $control) {
                $name = trim($control->getAttribute('aria-label'));
                if ($name === '' && $control->hasAttribute('id')) {
                    foreach ($xpath->query('//label[@for]') as $label) {
                        if ($label->getAttribute('for') === $control->getAttribute('id')) {
                            $name = trim($label->textContent);
                        }
                    }
                }
                if ($name === '' && $control->tagName === 'button') {
                    $name = trim($control->textContent);
                }

                $this->assertNotSame('', $name, $route.': '.$control->tagName.' '.$control->getAttribute('name').' needs an accessible name.');
            }
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }
}
