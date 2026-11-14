<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\RoomPreviewGeneration;

class ReservationPreview
{
    public function room(Reservation $reservation)
    {
        $recommendation = $reservation->recommendation;
        $room = $recommendation?->roomImage;
        if (! $recommendation || ! $room || $recommendation->user_id !== $reservation->user_id || $room->user_id !== $reservation->user_id) {
            return null;
        }

        return $room;
    }

    public function savedPath(Reservation $reservation, $room, RoomPhotoService $photos): ?string
    {
        if (! $room->gemini_consent_at) {
            return null;
        }
        $generation = RoomPreviewGeneration::where('room_image_id', $room->id)
            ->where('furniture_product_id', $reservation->furniture_product_id)->where('status', 'completed')->first();
        $paths = [data_get($generation?->placement_json, 'artifact_path'),
            $room->gemini_consent_at ? data_get($room->analysis, 'generated_previews.'.$reservation->furniture_product_id.'.path') : null];
        foreach ($paths as $path) {
            if (is_string($path) && $photos->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function available(Reservation $reservation, RoomPhotoService $photos): bool
    {
        $room = $this->room($reservation);

        return $room && ($photos->exists($room->image_path) || $this->savedPath($reservation, $room, $photos) !== null);
    }
}
