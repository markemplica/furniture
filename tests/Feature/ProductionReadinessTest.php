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
            'services.feedback.admin_email' => 'admin@example.test',
        ]);

        $checks = collect(app(ProductionReadiness::class)->inspect()['checks'])->keyBy('name');

        $this->assertFalse($checks['HTTPS application URL']['ok']);
        $this->assertFalse($checks['Email delivery']['ok']);
        $this->assertFalse($checks['Feedback admin recipient']['ok']);
        $this->assertStringContainsString('real production hostname', $checks['HTTPS application URL']['detail']);
    }

    public function test_production_environment_template_has_safe_placeholders_and_private_storage_path(): void
    {
        $template = file_get_contents(base_path('.env.production.example'));

        $this->assertIsString($template);
        $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', $template);
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $template);
        $this->assertMatchesRegularExpression('/^APP_URL=https:\/\/[^\s]+\.example$/m', $template);
        $values = \Dotenv\Dotenv::parse($template);
        $this->assertNotFalse(filter_var($values['ADMIN_FEEDBACK_EMAIL'], FILTER_VALIDATE_EMAIL));
        $this->assertMatchesRegularExpression('/^PRIVATE_STORAGE_ROOT=\/[^\s]+$/m', $template);
        $this->assertStringContainsString('at least 5 GB free', $template);
        $this->assertMatchesRegularExpression('/^APP_KEY=$/m', $template);
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=$/m', $template);
        $this->assertMatchesRegularExpression('/^MAIL_PASSWORD=$/m', $template);
        $this->assertMatchesRegularExpression('/^SESSION_SECURE_COOKIE=true$/m', $template);

        foreach (['.env.example', '.env.production.example'] as $filename) {
            $mysqlTemplate = file_get_contents(base_path($filename));
            $this->assertIsString($mysqlTemplate);
            $this->assertMatchesRegularExpression('/^DB_CONNECTION=mysql$/m', $mysqlTemplate);
            $this->assertMatchesRegularExpression('/^DB_CHARSET=utf8mb4$/m', $mysqlTemplate);
            $this->assertMatchesRegularExpression('/^DB_COLLATION=utf8mb4_unicode_ci$/m', $mysqlTemplate);
        }
    }

    public function test_private_storage_capacity_requires_five_gigabytes_free(): void
    {
        $root = sys_get_temp_dir();
        config(['filesystems.disks.local.root' => $root]);

        $checks = collect(app(ProductionReadiness::class)->inspect()['checks'])->keyBy('name');
        $freeBytes = disk_free_space($root);

        $this->assertSame($freeBytes !== false && $freeBytes >= 5 * 1024 ** 3, $checks['Private storage capacity']['ok']);
        $this->assertStringContainsString('at least 5.00 GB is required.', $checks['Private storage capacity']['detail']);
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
