<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = ['user_id', 'type', 'channel', 'recipient', 'subject', 'status', 'payload', 'sent_at', 'dispatch_attempts', 'dispatch_after'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime', 'dispatch_after' => 'datetime'];
    }
}
