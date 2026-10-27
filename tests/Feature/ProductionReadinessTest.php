<?php

namespace Tests\Feature;

use App\Services\ProductionReadiness;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    public function test_beta_template_placeholders_are_not_reported_as_ready(): void
    {
        config([
            'app.url' => 'https://replace-with-beta-domain.example',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'replace-with-beta-smtp-host',
            'mail.from.address' => 'noreply@replace-with-beta-domain.example',
            'services.feedback.admin_email' => '',
        ]);

        $checks = collect(app(ProductionReadiness::class)->inspect()['checks'])->keyBy('name');

        $this->assertFalse($checks['HTTPS application URL']['ok']);
        $this->assertFalse($checks['Email delivery']['ok']);
        $this->assertStringContainsString('real beta hostname', $checks['HTTPS application URL']['detail']);
    }

    public function test_concrete_https_and_smtp_settings_pass_configuration_checks(): void
    {
        config([
            'app.url' => 'https://beta.lancefurniture.test',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.lancefurniture.test',
            'mail.from.address' => 'noreply@lancefurniture.test',
            'services.feedback.admin_email' => 'admin@lancefurniture.test',
        ]);

        $checks = collect(app(ProductionReadiness::class)->inspect()['checks'])->keyBy('name');

        $this->assertTrue($checks['HTTPS application URL']['ok']);
        $this->assertTrue($checks['Email delivery']['ok']);
        $this->assertTrue($checks['Feedback admin recipient']['ok']);
        $this->assertStringContainsString('does not verify a real email', $checks['Email delivery']['detail']);
    }
}
