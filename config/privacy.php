<?php

return [
    'room_photo_max_count' => max(1, (int) env('ROOM_PHOTO_MAX_COUNT', 20)),
    'room_photo_max_bytes' => max(1, (int) env('ROOM_PHOTO_MAX_BYTES', 104857600)),
    'room_image_retention_days' => max(1, (int) env('ROOM_IMAGE_RETENTION_DAYS', 90)),
    'registration_verification_retention_hours' => max(1, (int) env('REGISTRATION_VERIFICATION_RETENTION_HOURS', 24)),
];
