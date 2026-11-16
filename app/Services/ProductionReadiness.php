<?php

namespace App\Services;

use App\Models\FurnitureProduct;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductionReadiness
{
    public function inspect(): array
    {
        $checks = [];
        $this->add($checks, 'Production environment', app()->environment('production'), 'APP_ENV is '.app()->environment().'.');
        $this->add($checks, 'Debug disabled', ! (bool) config('app.debug'), config('app.debug') ? 'APP_DEBUG is enabled.' : 'Debug output is disabled.');

        $url = (string) config('app.url');
        $urlHost = (string) parse_url($url, PHP_URL_HOST);
        $httpsReady = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && ! $this->isPlaceholder($urlHost);
        $urlDetail = $httpsReady ? $url : 'APP_URL must use HTTPS and the real production hostname; template placeholders are not ready.';
        $this->add($checks, 'HTTPS application URL', $httpsReady, $urlDetail);

        $database = config('database.default');
        $databaseReady = in_array($database, ['mysql', 'mariadb'], true);
        $databaseDetail = 'Configured connection: '.$database.'.';
        try {
            DB::connection()->select('select 1');
            $databaseDetail .= ' Database connection succeeded.';
        } catch (\Throwable $exception) {
            $databaseReady = false;
            $databaseDetail .= ' Database connection failed: '.$exception::class.'.';
        }
        $this->add($checks, 'Production database', $databaseReady, $databaseDetail);

        try {
            $activeProducts = FurnitureProduct::query()->where('status', 'active')->count();
        } catch (\Throwable) {
            $activeProducts = 0;
        }
        $this->add($checks, 'Active production catalog', $activeProducts > 0, $activeProducts > 0
            ? $activeProducts.' active catalog items are available.'
            : 'Add and verify the production furniture catalog before opening public traffic.');

        $mailer = (string) config('mail.default');
        $mailReady = in_array($mailer, ['smtp', 'ses', 'postmark', 'resend'], true);
        if ($mailer === 'smtp') {
            $mailReady = $mailReady
                && ! $this->isPlaceholder(config('mail.mailers.smtp.host'))
                && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false
                && ! $this->isPlaceholder(config('mail.from.address'));
        } elseif ($mailer === 'ses') {
            $mailReady = $mailReady && ! $this->isPlaceholder(config('services.ses.key')) && ! $this->isPlaceholder(config('services.ses.region'));
        } elseif ($mailer === 'postmark') {
            $mailReady = $mailReady && ! $this->isPlaceholder(config('services.postmark.token'));
        } elseif ($mailer === 'resend') {
            $mailReady = $mailReady && ! $this->isPlaceholder(config('services.resend.key'));
        }
        $mailDetail = $mailReady
            ? 'Configured mailer: '.$mailer.'. This does not verify a real email was delivered.'
            : 'Mailer '.$mailer.' is missing credentials or still has template placeholders.';
        $this->add($checks, 'Email delivery', $mailReady, $mailDetail);

        $feedbackRecipient = (string) config('services.feedback.admin_email');
        $feedbackReady = filter_var($feedbackRecipient, FILTER_VALIDATE_EMAIL) !== false && ! $this->isPlaceholder($feedbackRecipient);
        $this->add($checks, 'Feedback admin recipient', $feedbackReady, $feedbackReady ? 'A delivery address is configured.' : 'ADMIN_FEEDBACK_EMAIL must contain a real admin mailbox.');

        $geminiConfigured = app(GeminiClient::class)->isConfigured();
        $this->add($checks, 'Gemini fallback behavior', true, $geminiConfigured ? 'The selected AI provider is enabled with usage limits; failed requests use local guidance.' : 'The selected AI provider is disabled or unconfigured; local room estimates and chat guidance remain available.');
        $models = array_filter([AiProvider::model(), AiProvider::model(true), AiProvider::name() === 'gemini' ? config('services.gemini.fallback_model') : null]);
        $policyReady = ! config('services.gemini.enabled') || (
            (int) config('services.gemini.daily_request_limit') > 0 && (int) config('services.gemini.monthly_request_limit') > 0
            && (int) config('services.gemini.daily_budget_micro_usd') > 0 && (int) config('services.gemini.monthly_budget_micro_usd') > 0
            && AiProvider::name() === 'gemini'
            && array_diff($models, config('services.'.AiProvider::name().'.allowed_text_models', [])) === []
            && (! config('services.gemini.image_enabled') || in_array(config('services.gemini.image_model'), config('services.gemini.allowed_image_models', []), true))
        );
        $this->add($checks, 'AI spending policy', $policyReady, $policyReady
            ? 'Paid AI is disabled, or explicit request limits, reserved USD allowances and approved models are configured. App allowances are not a provider invoice guarantee.'
            : 'Before enabling paid AI, configure positive daily/monthly request and USD allowance limits and approved model IDs.');

        $filesystemDisk = (string) config('filesystems.default');
        $awsReady = $filesystemDisk !== 's3'
            || (! $this->isPlaceholder(config('filesystems.disks.s3.bucket'))
                && ! $this->isPlaceholder(config('filesystems.disks.s3.key'))
                && ! $this->isPlaceholder(config('filesystems.disks.s3.secret')));
        $this->add($checks, 'Object storage configuration', $awsReady, $filesystemDisk === 's3'
            ? ($awsReady ? 'S3 settings are present.' : 'AWS_BUCKET, AWS_ACCESS_KEY_ID, and AWS_SECRET_ACCESS_KEY are required for S3 storage.')
            : 'The local private disk is selected; AWS_BUCKET is not required.');

        $queue = (string) config('queue.default');
        $this->add($checks, 'Asynchronous queue configured', ! in_array($queue, ['sync', 'null'], true), 'Configured queue: '.$queue.'. This does not verify a worker is running; confirm a worker is supervised on the host.');

        $key = (string) config('app.key');
        $parsedKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $keyReady = is_string($parsedKey) && Encrypter::supported($parsedKey, config('app.cipher'));
        $this->add($checks, 'Application encryption key', $keyReady, $keyReady ? 'A valid key is configured; keep it private and stable.' : 'APP_KEY is missing or invalid for the configured cipher.');

        $root = realpath(config('filesystems.disks.local.root')) ?: config('filesystems.disks.local.root');
        $publicRoot = realpath(public_path()) ?: public_path();
        $storageWritable = is_dir($root) ? is_writable($root) : is_writable(dirname($root));
        $privateStorage = $storageWritable && ! str_starts_with(strtolower($root), strtolower($publicRoot));
        $this->add($checks, 'Private room-photo storage', $privateStorage, $privateStorage ? 'The local private disk is writable and outside the public web root.' : 'The local private disk must be persistent, writable, and outside the public web root.');

        $minimumFreeBytes = 5 * 1024 * 1024 * 1024;
        $freeBytes = @disk_free_space($root);
        $enoughSpace = $freeBytes !== false && $freeBytes >= $minimumFreeBytes;
        $spaceDetail = $freeBytes === false
            ? 'Free space could not be measured for the private storage volume.'
            : number_format($freeBytes / (1024 ** 3), 2).' GB is free; at least 5.00 GB is required.';
        $this->add($checks, 'Private storage capacity', $enoughSpace, $spaceDetail);

        $v2Enabled = (bool) config('roompreview.use_v2_pipeline');
        $gdReady = ! $v2Enabled || extension_loaded('gd');
        $gdDetail = ! $v2Enabled
            ? 'The v2 room-preview pipeline is disabled.'
            : (extension_loaded('gd') ? 'GD is loaded in this PHP runtime.' : 'GD is required while the v2 room-preview pipeline is enabled.');
        $this->add($checks, 'Room-preview image runtime', $gdReady, $gdDetail);

        try {
            $publicRoomFiles = Storage::disk('public')->allFiles('rooms');
            $publicRoomPhotosReady = $publicRoomFiles === [];
            $publicRoomPhotosDetail = $publicRoomPhotosReady
                ? 'No room photos or previews are present on the public disk.'
                : count($publicRoomFiles).' room files remain on the public disk; run room-images:secure-legacy and check again.';
        } catch (\Throwable $exception) {
            $publicRoomPhotosReady = false;
            $publicRoomPhotosDetail = 'The public room-file check failed: '.$exception::class.'.';
        }
        $this->add($checks, 'No public room photos', $publicRoomPhotosReady, $publicRoomPhotosDetail);

        return ['ready' => collect($checks)->every(fn ($check) => $check['ok']), 'checks' => $checks];
    }

    private function add(array &$checks, string $name, bool $ok, string $detail): void
    {
        $checks[] = compact('name', 'ok', 'detail');
    }

    private function isPlaceholder(mixed $value): bool
    {
        $value = strtolower(trim((string) $value));

        return $value === ''
            || str_contains($value, 'replace-with')
            || str_contains($value, 'placeholder')
            || preg_match('/(^|[.@])example(?:\.|$)/', $value) === 1;
    }
}
