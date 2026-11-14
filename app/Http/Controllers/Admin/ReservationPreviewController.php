<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\FurniturePlacementService;
use App\Services\ReservationPreview;
use App\Services\RoomPhotoService;

class ReservationPreviewController extends Controller
{
    public function show(Reservation $reservation, RoomPhotoService $photos, FurniturePlacementService $placements, ReservationPreview $previews)
    {
        $room = $previews->room($reservation);
        abort_unless($room, 404);
        $product = $reservation->product;
        $saved = $previews->savedPath($reservation, $room, $photos) !== null;
        $available = $photos->exists($room->image_path);
        $placement = $available && ! $saved ? $placements->forProduct($room->analysis ?? [], $product) : null;

        return response()->view('admin.reservations.preview', compact('reservation', 'room', 'product', 'saved', 'available', 'placement'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function image(Reservation $reservation, RoomPhotoService $photos, ReservationPreview $previews)
    {
        $room = $previews->room($reservation);
        abort_unless($room, 404);
        $path = $previews->savedPath($reservation, $room, $photos);
        abort_unless($path, 404);
        $mime = $photos->mimeType($path);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

        return response($photos->get($path), 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Vary' => 'Cookie, Authorization']);
    }
}
