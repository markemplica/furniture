<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentChecklistTest extends TestCase
{
    public function test_real_host_checklist_covers_each_operational_check_and_guards_restores(): void
    {
        $checklist = file_get_contents(base_path('DEPLOYMENT_CHECKLIST.md'));

        $this->assertIsString($checklist);
        $this->assertSame(8, substr_count($checklist, '**Expected:**'));

        foreach ([
            'artisan system:readiness',
            'artisan tinker --execute=',
            'MAIL_SEND_OK',
            'artisan queue:work database --queue=ai,default --sleep=3 --tries=3 --timeout=540',
            'QUEUE_CANARY_PROCESSED',
            'artisan schedule:run',
            'mysqldump',
            'private-storage.tar.gz',
            'sha256sum -c SHA256SUMS',
            'PRIVATE_FILE_COUNT_MATCHES',
            'trap resume_application EXIT',
            'test "$STAGING_DB_NAME" != "$PRODUCTION_DB_NAME"',
            'test "$STAGING_PRIVATE_ROOT" != "$PRODUCTION_PRIVATE_ROOT"',
            'artisan up',
        ] as $required) {
            $this->assertStringContainsString($required, $checklist);
        }

        $this->assertStringNotContainsString('migrate:fresh', $checklist);
        $this->assertStringNotContainsString('git reset --hard', $checklist);
        $this->assertStringNotContainsString('APP_KEY=base64:', $checklist);
    }
}
