<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestamp('dispatch_after')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex(['dispatch_after']);
            $table->dropColumn(['dispatch_attempts', 'dispatch_after']);
        });
    }
};
