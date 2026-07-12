<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supporting documents for a financing application (ERD §A.4, Fase 4-3). The
     * consumer uploads requirement files (KTP, payslips, …); the bank reviews
     * each one (accept/reject with a reason).
     *
     * `file` is a path/link placeholder — binary upload to object storage is
     * deferred to the media phase, consistent with designs/BAST. The file pointer
     * is sensitive, so the model marks it hidden (redacted in the audit trail).
     */
    public function up(): void
    {
        Schema::create('financing_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financing_id')->constrained('financings')->cascadeOnDelete();
            $table->string('name');
            $table->string('file')->nullable();
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['financing_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financing_documents');
    }
};
