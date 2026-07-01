<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record who signed each side of the BAST (Fase 3-3). The signer is a proper
     * attribute of the handover document — it appears on the PDF and can be
     * queried — not merely an audit trail. Filled by BastService::recordSignature;
     * audit_logs still records the mutation independently.
     */
    public function up(): void
    {
        Schema::table('bast', function (Blueprint $table) {
            $table->foreignId('signed_customer_by')->nullable()->after('signed_customer')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('signed_company_by')->nullable()->after('signed_company')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bast', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signed_customer_by');
            $table->dropConstrainedForeignId('signed_company_by');
        });
    }
};
