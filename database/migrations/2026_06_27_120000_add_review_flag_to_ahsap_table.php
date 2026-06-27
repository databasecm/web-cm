<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Staleness flag (ADR-0004, Fase 2A-3). When a material price moves, the
     * AHSAP that use it are flagged for review — base_price is NEVER changed
     * silently. A Manager clears the flag via the explicit resync action.
     */
    public function up(): void
    {
        Schema::table('ahsap', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('base_price');
            $table->string('review_reason')->nullable()->after('needs_review');
            $table->timestamp('review_requested_at')->nullable()->after('review_reason');

            $table->index('needs_review');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ahsap', function (Blueprint $table) {
            $table->dropIndex(['needs_review']);
            $table->dropColumn(['needs_review', 'review_reason', 'review_requested_at']);
        });
    }
};
