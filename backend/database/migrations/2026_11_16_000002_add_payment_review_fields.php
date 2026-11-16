<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estados del pago según RF19: pending / validated / rejected.
 *
 * Agrega quién y cuándo revisó el pago (validated_by, validated_at) y el motivo
 * de rechazo, y migra los estados anteriores:
 *   completed → validated
 *   failed    → rejected
 *   refunded  → rejected (con motivo "Reembolsado", para no perder el dato)
 *
 * El enum se amplía primero (estados viejos + nuevos), se migran los datos
 * y al final se restringe a los tres estados nuevos.
 */
return new class extends Migration
{
    private const OLD = ['pending', 'completed', 'failed', 'refunded'];

    private const NEW = ['pending', 'validated', 'rejected'];

    public function up(): void
    {
        $this->setStatuses([...self::OLD, 'validated', 'rejected']);

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('validated_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable()->after('validated_by');
            $table->text('rejection_reason')->nullable()->after('validated_at');
        });

        DB::table('payments')->where('status', 'completed')->update(['status' => 'validated']);
        DB::table('payments')->where('status', 'failed')->update(['status' => 'rejected']);
        DB::table('payments')->where('status', 'refunded')->update([
            'status' => 'rejected',
            'rejection_reason' => 'Reembolsado',
        ]);

        $this->setStatuses(self::NEW);
    }

    public function down(): void
    {
        $this->setStatuses([...self::OLD, 'validated', 'rejected']);

        DB::table('payments')->where('status', 'validated')->update(['status' => 'completed']);
        DB::table('payments')->where('status', 'rejected')->where('rejection_reason', 'Reembolsado')->update(['status' => 'refunded']);
        DB::table('payments')->where('status', 'rejected')->update(['status' => 'failed']);

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validated_by');
            $table->dropColumn(['validated_at', 'rejection_reason']);
        });

        $this->setStatuses(self::OLD);
    }

    private function setStatuses(array $statuses): void
    {
        Schema::table('payments', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->default('pending')->change();
        });
    }
};
