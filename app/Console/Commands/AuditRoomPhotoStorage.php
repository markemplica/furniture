<?php

namespace App\Console\Commands;

use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AuditRoomPhotoStorage extends Command
{
    protected $signature = 'room-images:audit-storage';

    protected $description = 'Report unreferenced private room files and room records with missing private files';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $referenced = [];
        $missingOriginals = 0;
        $missingPreviews = 0;
        $check = function (string $path, string $label, bool $original) use ($disk, &$referenced, &$missingOriginals, &$missingPreviews): void {
            $referenced[$path] = true;
            if ($disk->exists($path)) return;

            if ($original) $missingOriginals++;
            else $missingPreviews++;
            $this->warn("Missing private file for {$label}: {$path}");
        };

        RoomImage::query()->select('id', 'image_path', 'analysis')->orderBy('id')->chunkById(200, function ($rooms) use ($check): void {
            foreach ($rooms as $room) {
                $check($room->image_path, "room_images #{$room->id}", true);
                foreach (data_get($room->analysis, 'generated_previews', []) as $preview) {
                    $path = data_get($preview, 'path');
                    if (is_string($path) && $path !== '') $check($path, "room_images #{$room->id} preview", false);
                }
            }
        });
        RoomPreviewGeneration::query()->select('id', 'placement_json')->orderBy('id')->chunkById(200, function ($generations) use ($check): void {
            foreach ($generations as $generation) {
                $path = data_get($generation->placement_json, 'artifact_path');
                if (is_string($path) && $path !== '') $check($path, "room_preview_generations #{$generation->id}", false);
            }
        });

        $orphans = 0;
        $temporaryCutoff = now()->subDay()->timestamp;
        foreach ($disk->getDriver()->listContents('rooms', true) as $file) {
            if (! $file->isFile()) continue;
            $path = $file->path();
            if (isset($referenced[$path])) continue;
            if (str_contains($path, '/tmp/') && $file->lastModified() !== null && $file->lastModified() >= $temporaryCutoff) continue;

            $orphans++;
            $this->warn("Orphaned private room file: {$path}");
        }

        $this->info("Found {$orphans} orphaned private room files, {$missingOriginals} room rows with missing originals, and {$missingPreviews} missing preview references. No files were changed.");

        return self::SUCCESS;
    }
}
