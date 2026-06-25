<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Messages belonging to a persisted consultation thread (ERD §A.2). Guest
     * messages are never written here (ADR-0003); the only guest content that
     * lands in this table is a transcript copied once, with consent, when a
     * guest session is promoted on deal.
     */
    public function up(): void
    {
        Schema::create('consultation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->string('sender_type');
            $table->text('message');
            $table->string('attachment')->nullable();
            $table->timestamps();

            // Thread view: messages of a consultation in chronological order.
            $table->index(['consultation_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultation_messages');
    }
};
