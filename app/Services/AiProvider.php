<?php

namespace App\Services;

use App\Models\RoomImage;

/** Gemini is the only external AI provider; local guidance remains the fallback. */
class AiProvider
{
    public static function name(): string
    {
        return 'gemini';
    }

    public static function label(): string
    {
        return 'Google Gemini';
    }

    public static function key(): ?string
    {
        return config('services.gemini.key');
    }

    public static function model(bool $chat = false): ?string
    {
        return config('services.'.self::name().'.'.($chat ? 'chat_model' : 'model'));
    }

    public static function consentVersion(): string
    {
        return 'room-ai-v1';
    }

    public static function hasPhotoConsent(RoomImage $room): bool
    {
        return $room->gemini_consent_at !== null
            && ($room->gemini_consent_version === self::consentVersion()
                || $room->gemini_consent_version === null);
    }
}
