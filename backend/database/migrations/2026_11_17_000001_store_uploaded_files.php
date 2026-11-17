<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subida real de archivos al disco privado (storage/app/private).
 *
 * Antes se guardaban URLs de texto (receipt_url, file_url). Ahora se guarda
 * la ruta en el disco privado y el nombre original para la descarga:
 *   payments.receipt_url  → receipt_path + receipt_name
 *   materials.file_url    → file_path + file_name
 * Los documentos de matrícula siguen en enrollments.documents (JSON), ahora
 * como lista de {id, name, path, mime, size}.
 *
 * Los valores de texto anteriores se conservan en *_path, pero no son
 * archivos del disco: su descarga responde 404.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('receipt_url', 'receipt_path');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_name')->nullable()->after('receipt_path');
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->renameColumn('file_url', 'file_path');
        });
        Schema::table('materials', function (Blueprint $table) {
            $table->string('file_name')->nullable()->after('file_path');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_name');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn(['file_name', 'file_size']);
        });
        Schema::table('materials', function (Blueprint $table) {
            $table->renameColumn('file_path', 'file_url');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('receipt_name');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('receipt_path', 'receipt_url');
        });
    }
};
