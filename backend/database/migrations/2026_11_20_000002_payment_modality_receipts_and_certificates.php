<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 *  enrollments.payment_modality   modalidad elegida por el estudiante (unico | fraccionado...).
 *  enrollments.certificate_status pending (falta regularizar) | uploaded (lo subió) |
 *                                 delivered (la administración lo marcó como entregado).
 *  payments.receipt_number        N° de recibo de caja de la UNAJMA: único en todo el sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->string('payment_modality', 30)->nullable()->after('current_step');
            $table->string('certificate_status', 20)->nullable()->after('documents');
            $table->timestamp('certificate_delivered_at')->nullable()->after('certificate_status');
            $table->foreignId('certificate_delivered_by')->nullable()->after('certificate_delivered_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_number', 30)->nullable()->unique()->after('receipt_name');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn('receipt_number');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certificate_delivered_by');
            $table->dropColumn(['payment_modality', 'certificate_status', 'certificate_delivered_at']);
        });
    }
};
