<?php

// Test-only CLI fixture: never reachable through a web route or a real database.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('DB_DATABASE') !== getenv('FURNICRAFT_BROWSER_DB') || ! is_file((string) getenv('DB_DATABASE'))
    || ! preg_match('/^security-reset-(desktop|mobile)@example\.test$/', $argv[1] ?? '')) exit(1);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Models\PasswordResetCode::where('email', $argv[1])->delete();
App\Models\PasswordResetCode::create(['email' => $argv[1], 'code_hash' => Illuminate\Support\Facades\Hash::make('123456'), 'expires_at' => now()->addMinutes(15)]);
