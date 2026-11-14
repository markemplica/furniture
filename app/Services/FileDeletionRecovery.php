<?php

namespace App\Services;

use App\Models\PendingFileDeletion;
use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FileDeletionRecovery
{
    public function attempt(int $id): void
    {
        DB::transaction(function () use ($id) {
            $task = PendingFileDeletion::whereKey($id)->lockForUpdate()->first();
            if (! $task || $task->retry_at?->isFuture()) {
                return;
            }
            if ($task->disk === 'local' && $this->isReferenced($task->path)) {
                // A deterministic artifact path may have been reused since a failed deletion.
                $task->delete();

                return;
            }
            try {
                $disk = Storage::disk($task->disk);
                if (! $disk->exists($task->path) || ($disk->delete($task->path) && ! $disk->exists($task->path))) {
                    $task->delete();

                    return;
                }
                Log::error('Room file deletion failed after database commit', ['disk' => $task->disk, 'path' => $task->path]);
            } catch (Throwable $exception) {
                Log::error('Room file deletion failed after database commit', ['disk' => $task->disk, 'path' => $task->path, 'exception' => $exception::class]);
            }
            $task->update(['attempts' => $task->attempts + 1, 'retry_at' => now()->addMinutes(min(60, 2 ** min(6, $task->attempts)))]);
        });
    }

    private function isReferenced(string $path): bool
    {
        if (RoomImage::where('image_path', $path)->exists()
            || RoomPreviewGeneration::where('placement_json->artifact_path', $path)->exists()) {
            return true;
        }
        foreach (RoomImage::whereNotNull('analysis')->select(['id', 'analysis'])->cursor() as $room) {
            foreach (data_get($room->analysis, 'generated_previews', []) as $preview) {
                if (data_get($preview, 'path') === $path) {
                    return true;
                }
            }
        }

        return false;
    }
}
