<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmailVerificationCodeNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_code_is_encrypted_in_the_jobs_table_payload(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
            'queue.connections.database.table' => 'jobs',
        ]);
        $user = User::factory()->create();
        $code = '654321';

        $user->notify(new EmailVerificationCodeNotification($code));

        $this->assertDatabaseCount('jobs', 1);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString($code, $payload);

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $job = unserialize(Crypt::decrypt($data['data']['command']));

        $this->assertInstanceOf(SendQueuedNotifications::class, $job);
        $this->assertInstanceOf(EmailVerificationCodeNotification::class, $job->notification);
        $this->assertSame($code, $job->notification->code);
    }
}
