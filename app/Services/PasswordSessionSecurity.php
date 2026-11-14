<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PasswordSessionSecurity
{
    public function revoke(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($exceptSessionId, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
