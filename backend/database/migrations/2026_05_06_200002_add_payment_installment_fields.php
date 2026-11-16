<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('balance', 12, 2)->nullable()->after('amount');
            $table->integer('installment_number')->nullable()->after('balance');
            $table->integer('total_installments')->nullable()->after('installment_number');
            $table->json('installment_plan')->nullable()->after('total_installments');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['balance', 'installment_number', 'total_installments', 'installment_plan']);
        });
    }
};
