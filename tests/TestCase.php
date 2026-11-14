<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must opt into AI with dummy credentials and mocked responses.
        config([
            'services.ai.provider' => 'gemini',
            'services.groq.key' => null,
            'services.gemini.key' => null,
            // Generous fake-only budgets: tests must still opt in with a dummy key and Http::fake.
            'services.gemini.enabled' => true,
            'services.gemini.beta_expires_at' => null,
            'services.gemini.beta_budget_micro_usd' => 0,
            'services.gemini.image_enabled' => true,
            'services.gemini.daily_request_limit' => 100000,
            'services.gemini.monthly_request_limit' => 1000000,
            'services.gemini.daily_budget_micro_usd' => 10000000000,
            'services.gemini.monthly_budget_micro_usd' => 100000000000,
            'services.gemini.user_daily_limit' => 100000,
            'services.gemini.user_monthly_limit' => 1000000,
            'services.gemini.user_per_minute' => 100000,
            'services.gemini.project_per_minute' => 100000,
            'services.gemini.alerts_email_enabled' => false,
            'services.gemini.allowed_text_models' => ['primary', 'secondary', 'fallback', 'test-model', 'gemini-test', 'gemini-fallback-test', 'gemini-3.8-flash', 'gemini-3.6-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite'],
            'services.gemini.allowed_image_models' => ['test-image-model', 'gemini-3.1-flash-lite-image', 'gemini-3.1-flash-image', 'gemini-2.5-flash-image'],
        ]);
        Http::preventStrayRequests();
    }
}
