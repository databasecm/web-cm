<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Speeds up the common audit-log queries. The (entity, entity_id) index
     * already exists from the table's create migration; this adds an actor +
     * action composite for filtering the trail by who did what.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'action']);
        });
    }
};
