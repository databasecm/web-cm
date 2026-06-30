<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Links a project back to the consultation deal it grew from (Fase 2B
     * bridge). Nullable: projects may also be created without a consultation.
     * A unique index enforces one project per deal (the current default).
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('consultation_id')->nullable()->after('konsumen_id')
                ->constrained('consultations')->nullOnDelete();
            $table->unique('consultation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['consultation_id']);
            $table->dropConstrainedForeignId('consultation_id');
        });
    }
};
