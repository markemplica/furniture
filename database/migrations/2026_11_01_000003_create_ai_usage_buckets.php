<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_buckets', function (Blueprint $table) {
            $table->string('bucket', 32)->primary();
            $table->unsignedBigInteger('requests')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_buckets');
    }
};
