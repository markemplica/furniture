<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use App\Models\User;
use App\Services\RoomPhotoService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class RoomPhotoStorageSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_files_remain_on_rollback_and_are_deleted_only_after_commit(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $room = $this->room('rooms/transaction-original.png', 'rooms/transaction-preview.png');
        $photos = app(RoomPhotoService::class);

        DB::beginTransaction();
        try {
            $photos->deleteRoomFiles($room);
            $room->delete();
            Storage::disk('local')->assertExists(['rooms/transaction-original.png', 'rooms/transaction-preview.png']);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 1) DB::rollBack();
        }
        $this->assertModelExists($room);
        Storage::disk('local')->assertExists(['rooms/transaction-original.png', 'rooms/transaction-preview.png']);
        $room = RoomImage::findOrFail($room->id);

        DB::transaction(function () use ($photos, $room): void {
            $photos->deleteRoomFiles($room);
            $room->delete();
            Storage::disk('local')->assertExists(['rooms/transaction-original.png', 'rooms/transaction-preview.png']);
        });

        $this->assertModelMissing($room);
        Storage::disk('local')->assertMissing(['rooms/transaction-original.png', 'rooms/transaction-preview.png']);
    }

    public function test_preview_deletion_waits_for_commit_and_rollback_keeps_its_file_and_row(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $room = $this->room('rooms/consent-original.png', 'rooms/consent-preview.png');
        $generation = RoomPreviewGeneration::create([
            'room_image_id' => $room->id,
            'furniture_product_id' => $this->product()->id,
            'status' => 'completed',
            'placement_json' => ['artifact_path' => 'rooms/consent-artifact.png'],
        ]);
        Storage::disk('local')->put('rooms/consent-artifact.png', 'artifact');
        $photos = app(RoomPhotoService::class);

        DB::beginTransaction();
        try {
            $photos->deleteGeneratedPreviews($room);
            Storage::disk('local')->assertExists(['rooms/consent-preview.png', 'rooms/consent-artifact.png']);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 1) DB::rollBack();
        }
        $this->assertSame('completed', $generation->fresh()->status);
        Storage::disk('local')->assertExists(['rooms/consent-preview.png', 'rooms/consent-artifact.png']);

        DB::transaction(function () use ($photos, $room): void {
            $photos->deleteGeneratedPreviews($room);
            Storage::disk('local')->assertExists(['rooms/consent-preview.png', 'rooms/consent-artifact.png']);
        });

        $this->assertSame('cancelled', $generation->fresh()->status);
        $this->assertNull(data_get($generation->fresh()->placement_json, 'artifact_path'));
        Storage::disk('local')->assertMissing(['rooms/consent-preview.png', 'rooms/consent-artifact.png']);
        Storage::disk('local')->assertExists('rooms/consent-original.png');
    }

    public function test_customer_room_deletion_removes_the_row_and_files_after_the_request_commits(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $room = $this->room('rooms/customer-original.png', 'rooms/customer-preview.png');

        $this->actingAs($room->user)->delete(route('room.destroy', $room))
            ->assertRedirect(route('preferences.edit'));

        $this->assertModelMissing($room);
        Storage::disk('local')->assertMissing(['rooms/customer-original.png', 'rooms/customer-preview.png']);
    }

    public function test_failed_file_deletion_is_logged_after_commit(): void
    {
        $local = Mockery::mock(FilesystemAdapter::class);
        $local->shouldReceive('exists')->once()->with('rooms/delete-fails.png')->andReturnTrue();
        $local->shouldReceive('delete')->once()->with('rooms/delete-fails.png')->andReturnFalse();
        $public = Mockery::mock(FilesystemAdapter::class);
        $public->shouldReceive('exists')->once()->with('rooms/delete-fails.png')->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($local);
        Storage::shouldReceive('disk')->once()->with('public')->andReturn($public);
        Log::shouldReceive('error')->once()->with('Room file deletion failed after database commit', [
            'disk' => 'local', 'path' => 'rooms/delete-fails.png',
        ]);

        DB::transaction(fn () => app(RoomPhotoService::class)->deleteGenerated('rooms/delete-fails.png'));
        $this->assertDatabaseHas('pending_file_deletions', ['disk' => 'local', 'path' => 'rooms/delete-fails.png', 'attempts' => 1]);
    }

    public function test_retry_deletes_only_the_failed_intent_and_preserves_active_files(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('rooms/retry.png', 'old');
        Storage::disk('local')->put('rooms/unrelated.png', 'keep');
        $task = \App\Models\PendingFileDeletion::create(['disk' => 'local', 'path' => 'rooms/retry.png', 'attempts' => 1, 'retry_at' => now()->subMinute()]);
        $this->artisan('room-images:retry-deletions')->assertSuccessful();
        Storage::disk('local')->assertMissing('rooms/retry.png');
        Storage::disk('local')->assertExists('rooms/unrelated.png');
        $this->assertModelMissing($task);
        $room = $this->room('rooms/reused.png', 'rooms/reused-preview.png');
        \App\Models\PendingFileDeletion::create(['disk' => 'local', 'path' => $room->image_path]);
        $this->artisan('room-images:retry-deletions')->assertSuccessful();
        Storage::disk('local')->assertExists($room->image_path);
    }

    public function test_upload_pixel_limit_is_checked_before_storing_or_decoding(): void
    {
        Storage::fake('local');
        config(['roompreview.max_pixels' => 100]);
        $upload = \Illuminate\Http\UploadedFile::fake()->createWithContent('room.png', file_get_contents(base_path('tests/Fixtures/room-600.png')));
        try {
            app(RoomPhotoService::class)->storeUpload($upload, 1);
            $this->fail('Oversized image should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('room_image', $exception->errors());
            $this->assertEmpty(Storage::disk('local')->allFiles());
        }
    }

    public function test_audit_reports_orphans_missing_originals_and_missing_preview_references_without_deleting(): void
    {
        Storage::fake('local');
        $missingRoom = $this->room('rooms/missing-original.png', 'rooms/missing-preview.png');
        Storage::disk('local')->delete(['rooms/missing-original.png', 'rooms/missing-preview.png']);
        $existingRoom = $this->room('rooms/existing-original.png', 'rooms/existing-preview.png');
        $generation = RoomPreviewGeneration::create([
            'room_image_id' => $existingRoom->id,
            'furniture_product_id' => $this->product()->id,
            'status' => 'completed',
            'placement_json' => ['artifact_path' => 'rooms/missing-artifact.png'],
        ]);
        Storage::disk('local')->put('rooms/orphan.png', 'unreferenced');
        Storage::disk('local')->put('rooms/1/tmp/active.png', 'temporary');

        $this->artisan('room-images:audit-storage')
            ->expectsOutput("Missing private file for room_images #{$missingRoom->id}: rooms/missing-original.png")
            ->expectsOutput("Missing private file for room_images #{$missingRoom->id} preview: rooms/missing-preview.png")
            ->expectsOutput("Missing private file for room_preview_generations #{$generation->id}: rooms/missing-artifact.png")
            ->expectsOutput('Orphaned private room file: rooms/orphan.png')
            ->expectsOutput('Found 1 orphaned private room files, 1 room rows with missing originals, and 2 missing preview references. No files were changed.')
            ->assertExitCode(0);

        Storage::disk('local')->assertExists(['rooms/orphan.png', 'rooms/1/tmp/active.png', 'rooms/existing-original.png']);
        $this->assertModelExists($missingRoom);
        $this->assertModelExists($existingRoom);
    }

    private function room(string $path, string $previewPath): RoomImage
    {
        Storage::disk('local')->put($path, 'original');
        Storage::disk('local')->put($previewPath, 'preview');

        return RoomImage::create([
            'user_id' => User::factory()->create()->id,
            'image_path' => $path,
            'analysis' => ['generated_previews' => [['path' => $previewPath]]],
        ]);
    }

    private function product(): FurnitureProduct
    {
        return FurnitureProduct::create([
            'name' => 'Room Audit Table', 'slug' => 'room-audit-table', 'category' => 'Dining Table',
            'description' => 'A table.', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural',
            'style' => 'Modern', 'price' => 20000, 'status' => 'active',
        ]);
    }
}
