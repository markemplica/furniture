<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FeedbackReport extends Model
{
    public const REDACTED_MESSAGE = '[Message removed on account deletion: not reviewed as free of personal data.]';

    protected $fillable = ['user_id', 'email', 'category', 'subject', 'message'];

    protected function casts(): array
    {
        return ['message_is_personal_data_free' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $report): void {
            if ($report->isDirty('message')) {
                $report->message_is_personal_data_free = false;
            }
        });
    }

    public static function anonymizeForUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Only an explicit internal review permits retaining free-text messages.
            // This flag is deliberately excluded from mass-assignable attributes.
            static::where('user_id', $user->id)
                ->where('message_is_personal_data_free', false)
                ->update(['message' => self::REDACTED_MESSAGE]);

            static::where('user_id', $user->id)->update([
                'user_id' => null,
                'email' => null,
                'name' => 'Deleted user',
                'subject' => 'Feedback from deleted user',
            ]);
        });
    }
}
