<?php

namespace App\Services;

use App\Models\RoomImage;

/** Provider selection is explicit: never fail over to another billable provider. */
class AiProvider
{
    public static function name(): string
    {
        return (string) config('services.ai.provider', 'gemini');
    }

    public static function label(): string
    {
        return self::name() === 'groq' ? 'Groq' : 'Google Gemini';
    }

    public static function key(): ?string
    {
        return in_array(self::name(), ['groq', 'gemini'], true) ? config('services.'.self::name().'.key') : null;
    }

    public static function model(bool $chat = false): ?string
    {
        return config('services.'.self::name().'.'.($chat ? 'chat_model' : 'model'));
    }

    public static function consentVersion(): string
    {
        return self::name() === 'groq' ? 'room-ai-groq-v1' : 'room-ai-v1';
    }

    public static function hasPhotoConsent(RoomImage $room): bool
    {
        return $room->gemini_consent_at !== null
            && ($room->gemini_consent_version === self::consentVersion()
                || (self::name() === 'gemini' && $room->gemini_consent_version === null));
    }
}
