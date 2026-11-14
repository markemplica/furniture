<?php

namespace Tests\Feature;

use App\Models\RoomImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomUploadSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        Storage::fake('local');
        Storage::fake('public');
        $user = User::factory()->create();
        $user->preference()->create(['furniture_type' => 'Chair', 'budget_min' => 1000, 'budget_max' => 20000]);
        $this->actingAs($user);

        return $user;
    }

    private function upload()
    {
        return $this->post(route('room.store'), ['room_image' => UploadedFile::fake()->image('room.png', 800, 600)]);
    }

    public function test_failed_database_insert_removes_the_new_file(): void
    {
        $this->customer();
        Event::listen('eloquent.creating: '.RoomImage::class, fn () => throw new \RuntimeException('Simulated insert failure'));
        $this->withoutExceptionHandling();
        try {
            $this->upload();
            $this->fail('Expected an insert failure');
        } catch (\RuntimeException $failure) {
            $this->assertSame('Simulated insert failure', $failure->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.RoomImage::class);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('room_images', 0);
    }

    public function test_duplicate_upload_reuses_analysis_without_preview_ownership(): void
    {
        $this->customer();
        $this->upload()->assertRedirect();
        $original = RoomImage::first();
        $analysis = $original->analysis;
        $analysis['generated_previews'] = ['1' => ['path' => 'private/original-preview.png']];
        $analysis['generated_preview_attempts'] = ['1' => 4];
        $original->update(['analysis' => $analysis]);
        $this->upload()->assertRedirect();
        $copy = RoomImage::latest('id')->first();
        $this->assertNotSame($original->image_path, $copy->image_path);
        $this->assertArrayNotHasKey('generated_previews', $copy->analysis);
        $this->assertArrayNotHasKey('generated_preview_attempts', $copy->analysis);
        $this->assertSame($analysis, $original->fresh()->analysis);
    }

    public function test_count_and_byte_limits_reject_uploads_without_orphans(): void
    {
        $this->customer();
        config(['privacy.room_photo_max_bytes' => 1]);
        $this->upload()->assertSessionHasErrors('room_image');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('room_images', 0);
        config(['privacy.room_photo_max_bytes' => 104857600, 'privacy.room_photo_max_count' => 1]);
        $this->upload()->assertRedirect();
        $files = Storage::disk('local')->allFiles();
        $this->upload()->assertSessionHasErrors('room_image');
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('room_images', 1);
    }

    public function test_thumbnail_is_small_private_and_owner_authorized(): void
    {
        $owner = $this->customer();
        $this->upload()->assertRedirect();
        $room = RoomImage::first();
        $response = $this->get(route('room-images.thumbnail', $room))->assertOk()->assertHeader('Content-Type', 'image/png');
        $dimensions = getimagesizefromstring($response->getContent());
        $this->assertLessThanOrEqual(280, max($dimensions[0], $dimensions[1]));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->actingAs(User::factory()->create())->get(route('room-images.thumbnail', $room))->assertForbidden();
        $this->actingAs($owner)->get(route('room-images.original', $room))->assertOk();
    }

    public function test_photo_list_is_paginated_and_uses_philippine_time(): void
    {
        $owner = $this->customer();
        foreach (range(1, 8) as $index) {
            RoomImage::forceCreate(['user_id' => $owner->id,
                'image_path' => 'rooms/'.$index.'.png', 'analysis_status' => 'fallback',
                'created_at' => '2026-11-07 23:30:00']);
        }
        $response = $this->get(route('preferences.edit'))->assertOk()->assertSee('November 8, 2026 · 7:30 AM');
        $this->assertCount(6, $response->viewData('rooms'));
        $this->assertSame(8, $response->viewData('rooms')->total());
        $this->assertStringContainsString('/thumbnail', $response->getContent());
        $second = $this->get(route('preferences.edit', ['photos_page' => 2]))->assertOk();
        $this->assertCount(2, $second->viewData('rooms'));
    }
}
