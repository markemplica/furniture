<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('name')->nullable();
            $table->boolean('message_is_personal_data_free')->default(false);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        // Keep anonymized addresses empty when restoring the non-nullable column.
        DB::table('feedback_reports')->whereNull('email')->update(['email' => '']);

        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn(['name', 'message_is_personal_data_free']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
