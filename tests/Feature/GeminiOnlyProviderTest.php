<?php

namespace Tests\Feature;

use App\Models\RoomImage;
use App\Services\AiProvider;
use Tests\TestCase;

class GeminiOnlyProviderTest extends TestCase
{
    public function test_provider_cannot_switch_away_from_gemini(): void
    {
        config(['services.ai.provider'=>'retired-provider','services.gemini.key'=>'fake-gemini-key']);
        $this->assertSame('gemini', AiProvider::name());
        $this->assertSame('Google Gemini', AiProvider::label());
        $this->assertSame('fake-gemini-key', AiProvider::key());
    }

    public function test_consent_from_another_provider_is_not_converted(): void
    {
        $room = new RoomImage(['gemini_consent_at'=>now(),'gemini_consent_version'=>'room-ai-retired-provider-v1']);
        $this->assertFalse(AiProvider::hasPhotoConsent($room));
        $room->gemini_consent_version='room-ai-v1';
        $this->assertTrue(AiProvider::hasPhotoConsent($room));
        $room->gemini_consent_version=null;
        $this->assertTrue(AiProvider::hasPhotoConsent($room));
        $room->gemini_consent_at=null;
        $this->assertFalse(AiProvider::hasPhotoConsent($room));
    }
}
