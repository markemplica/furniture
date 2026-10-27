<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedbackReport extends Model
{
    protected $fillable = ['user_id', 'email', 'category', 'subject', 'message'];
}
