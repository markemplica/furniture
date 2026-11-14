<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ai' => ['provider' => env('AI_PROVIDER', 'gemini')],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'chat_model' => env('GROQ_CHAT_MODEL', 'openai/gpt-oss-20b'),
        'model' => env('GROQ_VISION_MODEL', 'qwen/qwen3.8-27b'),
        'allowed_text_models' => ['openai/gpt-oss-20b', 'qwen/qwen3.8-27b'],
        'max_inline_bytes' => 3000000,
    ],

    // Shared spending gates retain their existing names for deployed compatibility.
    'gemini' => [
        // Missing limits fail closed. Paid AI must be explicitly enabled and budgeted.
        'enabled' => (bool) env('GEMINI_ENABLED', false),
        'beta_expires_at' => env('GEMINI_BETA_EXPIRES_AT'),
        'beta_budget_micro_usd' => max(0, (int) round((float) env('GEMINI_BETA_BUDGET_USD', 0) * 1000000)),
        'daily_request_limit' => max(0, (int) env('GEMINI_DAILY_REQUEST_LIMIT', 0)),
        'monthly_request_limit' => max(0, (int) env('GEMINI_MONTHLY_REQUEST_LIMIT', 0)),
        'daily_budget_micro_usd' => max(0, (int) round((float) env('GEMINI_DAILY_BUDGET_USD', 0) * 1000000)),
        'monthly_budget_micro_usd' => max(0, (int) round((float) env('GEMINI_MONTHLY_BUDGET_USD', 0) * 1000000)),
        'project_per_minute' => max(1, min(60, (int) env('GEMINI_PROJECT_RPM', 10))),
        'user_per_minute' => max(1, min(20, (int) env('GEMINI_USER_RPM', 5))),
        'user_daily_limit' => max(0, (int) env('GEMINI_USER_DAILY_LIMIT', 20)),
        'user_monthly_limit' => max(0, (int) env('GEMINI_USER_MONTHLY_LIMIT', 200)),
        'text_reserve_micro_usd' => 100000,
        'chat_reserve_micro_usd' => 10000,
        'image_reserve_micro_usd' => 250000,
        'image_enabled' => (bool) env('GEMINI_IMAGE_ENABLED', false),
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-3.1-flash-lite'),
        'allowed_text_models' => ['gemini-3.1-flash-lite'],
        'allowed_image_models' => ['gemini-3.1-flash-lite-image'],
        'max_calls_per_operation' => 3,
        'max_text_bytes' => 24000,
        'max_inline_bytes' => 8000000,
        'chat_output_tokens' => 384,
        'analysis_output_tokens' => 4096,
        'alerts_email_enabled' => (bool) env('AI_ALERT_EMAIL_ENABLED', false),
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
        'fallback_model' => env('GEMINI_FALLBACK_MODEL'),
        'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-lite-image'),
        'image_fallback_model' => env('GEMINI_IMAGE_FALLBACK_MODEL'),
    ],

    'feedback' => [
        'admin_email' => env('ADMIN_FEEDBACK_EMAIL'),
    ],

];
