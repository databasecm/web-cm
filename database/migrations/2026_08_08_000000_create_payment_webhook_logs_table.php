<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every real payment-gateway callback (ADR-0013). Append-only
 * observability — separate from the money flow (a failed write never blocks a
 * settlement). The stored payload is REDACTED (no signature_key / signature).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');                       // simulated | midtrans
            $table->string('gateway_ref')->nullable()->index(); // order_id / charge ref
            $table->string('transaction_status')->nullable();
            $table->boolean('verified');                     // did the signature verify?
            $table->string('outcome');                       // settled|noop|rejected|unknown_ref
            $table->string('ip')->nullable();
            $table->json('payload')->nullable();             // redacted (no signature_key)
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
    }
};
