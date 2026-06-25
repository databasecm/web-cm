<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Persisted consultation threads for logged-in consumers (ERD §A.2). Guest
     * (no-login) sessions are NEVER stored here — they live only in Redis with a
     * TTL and vanish when the session ends (CLAUDE.md §7, ADR-0003). A row only
     * appears here for an authenticated consumer, or once a guest session is
     * promoted on deal.
     */
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();

            // Nullable: a guest-originated thread may be persisted on deal before
            // its consumer account exists for a beat; otherwise always set.
            $table->foreignId('konsumen_id')->nullable()->constrained('users')->nullOnDelete();

            // Nullable by design: consultations are routed at the bidang level and
            // a Manager is assigned only when one first responds (claim model,
            // ADR-0003) — avoiding threads orphaned to an offline Manager.
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('bidang');
            $table->boolean('is_guest')->default(false);
            $table->string('status')->default('open');
            $table->timestamps();

            // Manager inbox: list open threads in my bidang.
            $table->index(['bidang', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
