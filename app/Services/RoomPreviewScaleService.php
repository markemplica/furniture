<?php

namespace App\Services;

class RoomPreviewScaleService
{
    public function limitWidth(float $requested, string $type, float $imageAspect, float $frameAspect, bool $floorDetected): float
    {
        $rules = config('roompreview.visual_scale');
        $family = match (true) {
            preg_match('/bed|bunk|double.deck/i', $type) === 1 => 'bed',
            preg_match('/sala|sofa|couch|sectional|living.room/i', $type) === 1 => 'living',
            preg_match('/dining/i', $type) === 1 => 'dining',
            preg_match('/cabinet|wardrobe|dresser|shelf|storage/i', $type) === 1 => 'storage',
            preg_match('/chair|stool|bench|seating/i', $type) === 1 => 'seating',
            preg_match('/table|desk|console/i', $type) === 1 => 'table',
            default => 'default',
        };
        return min($requested, $rules['widths'][$family],
            $floorDetected ? 1 : $rules['width_without_floor'],
            ($floorDetected ? $rules['height'] : $rules['height_without_floor']) * $imageAspect / $frameAspect);
    }
}
