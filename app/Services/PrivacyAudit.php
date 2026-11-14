<?php

namespace App\Services;

use App\Models\PrivacyAuditEvent;
use Illuminate\Support\Facades\Auth;

class PrivacyAudit
{
    public function record(string $action, ?string $subjectType = null, ?int $subjectId = null, array $metadata = [], ?int $userId = null): void
    {
        if (AiProvider::name() === 'groq') {
            $action = str_replace('gemini', 'groq', $action);
            if (str_starts_with($action, 'room_photo_')) {
                $metadata['provider'] = 'groq';
            }
        }
        PrivacyAuditEvent::create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata ?: null,
        ]);
    }
}
