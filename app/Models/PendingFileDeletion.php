<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingFileDeletion extends Model
{
    protected $fillable = ['disk', 'path', 'attempts', 'retry_at'];

    protected function casts(): array
    {
        return ['retry_at' => 'datetime'];
    }
}
