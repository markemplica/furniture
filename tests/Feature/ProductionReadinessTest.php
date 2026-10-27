<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Services\ProductionReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_template_placeholders_are_not_reported_as_ready(): void
    {
        $this->activeProduct();
        config([
            'app.url' => 'https://your-production-domain.example',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'replace-with-smtp-host',
            'mail.from.address' => 'noreply@your-production-domain.example',
            'services.feedback.admin_email' => '',
        ]);

        $checks = collect(app(ProductionReadiness::class)->inspect()['checks'])->keyBy('name');

        $this->assertFalse($checks['HTTPS application URL']['ok']);
        $this->assertFalse($checks['Email delivery']['ok']);
        $this->assertStringContainsString('real production hostname', $checks['HTTPS application URL']['detail']);
    }

    public function test_concrete_https_and_smtp_settings_pass_configuration_checks(): void
    {
        $this->activeProduct();
        config([
            'app.url' => 'https://beta.lancefurniture.test',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.lancefurniture.test',
            'mail.from.address' => 'noreply@lancefurniture.test',
            'services.feedback.admin_email' => 'admin@lancefurniture.test',
        ]);

        $checks = collect(app(ProductionReadiness::class)->inspect()['checks'])->keyBy('name');

        $this->assertTrue($checks['HTTPS application URL']['ok']);
        $this->assertTrue($checks['Active production catalog']['ok']);
        $this->assertTrue($checks['Email delivery']['ok']);
        $this->assertTrue($checks['Feedback admin recipient']['ok']);
        $this->assertStringContainsString('does not verify a real email', $checks['Email delivery']['detail']);
    }

    private function activeProduct(): void
    {
        FurnitureProduct::create([
            'name' => 'Production Readiness Product',
            'slug' => 'production-readiness-product',
            'category' => 'Chair',
            'description' => 'Readiness fixture.',
            'wood_type' => 'Gmelina',
            'varnish_finish' => 'Natural',
            'style' => 'Modern',
            'price' => 1000,
            'status' => 'active',
        ]);
    }
}
