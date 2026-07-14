<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency key for offline field sync (Fase 5-4). A Mandor's device sends
     * a unique client_id (UUID) with each attendance/report; the server dedups by
     * it so a retried batch (lost signal → resend) never creates a duplicate.
     * Nullable + unique: server-side / historical rows keep NULL, synced rows are
     * unique.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->uuid('client_id')->nullable()->unique()->after('id');
        });

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->uuid('client_id')->nullable()->unique()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(['client_id']);
            $table->dropColumn('client_id');
        });

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropUnique(['client_id']);
            $table->dropColumn('client_id');
        });
    }
};
