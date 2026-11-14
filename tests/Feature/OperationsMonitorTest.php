<?php

namespace Tests\Feature;

use App\Models\PendingFileDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_fails_for_a_repeated_deletion_failure_and_recovers_when_cleared(): void
    {
        $this->artisan('system:operations')->assertSuccessful();
        $task = PendingFileDeletion::create(['disk' => 'local', 'path' => 'rooms/synthetic-monitor.png', 'attempts' => 6]);
        $this->artisan('system:operations')->assertFailed();
        $task->delete();
        $this->artisan('system:operations')->assertSuccessful();
    }
}
