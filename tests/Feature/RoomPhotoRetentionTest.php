<?php

namespace Tests\Feature;

use App\Models\PrivacyAuditEvent;
use App\Models\RoomImage;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomPhotoRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_prune_deletes_photos_previews_and_rows_older_than_configured_period(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
        config(['privacy.room_image_retention_days' => 90]);
        $user = User::factory()->create();
        $expired = $this->room($user, 'expired');
        $expired->forceFill(['created_at' => now()->subDays(90)->subSecond()])->save();
        $boundary = $this->room($user, 'boundary');
        $boundary->forceFill(['created_at' => now()->subDays(90)])->save();

        $this->artisan('room-images:prune')->assertExitCode(0);

        $this->assertModelMissing($expired);
        Storage::disk('local')->assertMissing(['rooms/expired.png', 'rooms/expired-preview.png']);
        $this->assertModelExists($boundary);
        Storage::disk('local')->assertExists(['rooms/boundary.png', 'rooms/boundary-preview.png']);
        $this->assertDatabaseHas('privacy_audit_events', [
            'action' => 'room_photo_retention_expired',
            'subject_type' => 'room_image',
            'subject_id' => $expired->id,
            'user_id' => $user->id,
        ]);
        $this->assertSame(90, PrivacyAuditEvent::where('action', 'room_photo_retention_expired')->firstOrFail()->metadata['retention_days']);
    }

    public function test_prune_respects_a_shorter_configured_period(): void
    {
        Storage::fake('local');
        config(['privacy.room_image_retention_days' => 7]);
        $user = User::factory()->create();
        $expired = $this->room($user, 'seven-days-old');
        $expired->forceFill(['created_at' => now()->subDays(8)])->save();
        $recent = $this->room($user, 'recent');

        $this->artisan('room-images:prune')->assertExitCode(0);

        $this->assertModelMissing($expired);
        Storage::disk('local')->assertMissing('rooms/seven-days-old.png');
        $this->assertModelExists($recent);
        Storage::disk('local')->assertExists('rooms/recent.png');
    }

    public function test_account_deletion_removes_its_room_files_and_rows_and_records_each_deletion(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $first = $this->room($user, 'first');
        $second = $this->room($user, 'second');
        $other = $this->room(User::factory()->create(), 'other');

        $this->actingAs($user)->delete(route('profile.destroy'), [
            'current_password' => 'password',
            'confirm_account_deletion' => 'DELETE',
        ])->assertRedirect(route('home'));

        $this->assertModelMissing($user);
        $this->assertModelMissing($first);
        $this->assertModelMissing($second);
        Storage::disk('local')->assertMissing(['rooms/first.png', 'rooms/first-preview.png', 'rooms/second.png', 'rooms/second-preview.png']);
        $this->assertModelExists($other);
        Storage::disk('local')->assertExists('rooms/other.png');
        $this->assertSame(2, PrivacyAuditEvent::where('action', 'room_photo_deleted_with_account')->count());
        $this->assertTrue(PrivacyAuditEvent::where('action', 'room_photo_deleted_with_account')->get()->every(
            fn (PrivacyAuditEvent $event) => $event->user_id === null && $event->subject_id === null
        ));
    }

    public function test_prune_is_scheduled_daily_and_policy_describes_retention_and_consent(): void
    {
        config(['privacy.room_image_retention_days' => 90]);
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'room-images:prune'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->get(route('privacy-policy'))->assertOk()
            ->assertSee('90 days from upload')
            ->assertSee('only when you explicitly select the optional AI consent checkbox')
            ->assertSee('Deleting your account removes its room photos and room records immediately.');
    }

    private function room(User $user, string $name): RoomImage
    {
        $path = "rooms/{$name}.png";
        $previewPath = "rooms/{$name}-preview.png";
        Storage::disk('local')->put($path, 'room photo');
        Storage::disk('local')->put($previewPath, 'generated preview');

        return RoomImage::create([
            'user_id' => $user->id,
            'image_path' => $path,
            'analysis' => ['generated_previews' => [['path' => $previewPath]]],
        ]);
    }
}
