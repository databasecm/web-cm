<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference of the active payment charge for a term (Fase 3-5). Paired with
     * the existing va_number, it identifies the charge a gateway created so a
     * callback (Fase 3-6) can be matched back to the installment. Set only for an
     * unlocked term with an open charge; a real gateway later reuses the same
     * column behind the PaymentGateway interface.
     */
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->string('gateway_ref')->nullable()->after('va_number');
            $table->index('gateway_ref');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropIndex(['gateway_ref']);
            $table->dropColumn('gateway_ref');
        });
    }
};
