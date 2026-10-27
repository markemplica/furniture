<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $indexes = [
        'room_images' => [
            'room_images_user_created_idx' => ['user_id', 'created_at'],
            'room_images_analyzed_at_idx' => ['analyzed_at'],
        ],
        'recommendations' => [
            'recommendations_user_created_idx' => ['user_id', 'created_at'],
        ],
        'reservations' => [
            'reservations_user_created_idx' => ['user_id', 'created_at'],
            'reservations_status_created_idx' => ['status', 'created_at'],
        ],
        'password_reset_codes' => [
            'password_reset_email_used_expires_idx' => ['email', 'used_at', 'expires_at'],
        ],
        'chat_messages' => [
            'chat_messages_recommendation_created_idx' => ['recommendation_id', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            Schema::table($tableName, function (Blueprint $table) use ($indexes): void {
                foreach ($indexes as $name => $columns) {
                    $table->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            Schema::table($tableName, function (Blueprint $table) use ($indexes): void {
                foreach (array_keys($indexes) as $name) {
                    $table->dropIndex($name);
                }
            });
        }
    }
};
