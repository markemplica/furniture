<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_buckets', function (Blueprint $table) {
            $table->string('bucket', 96)->change();
            $table->unsignedBigInteger('reserved_micro_usd')->default(0);
        });
        Schema::create('ai_usage_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 128)->unique();
            $table->string('code', 64);
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->timestamps();
        });
        Schema::table('ai_service_events', function (Blueprint $table) {
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('thinking_tokens')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_service_events', fn (Blueprint $table) => $table->dropColumn(['input_tokens', 'output_tokens', 'thinking_tokens']));
        Schema::dropIfExists('ai_usage_alerts');
        Schema::table('ai_usage_buckets', fn (Blueprint $table) => $table->dropColumn('reserved_micro_usd'));
    }
};
