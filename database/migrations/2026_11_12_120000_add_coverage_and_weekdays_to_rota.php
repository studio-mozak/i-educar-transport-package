<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules.rota_transporte_escolar', function (Blueprint $table) {
            $table->enum('abrangencia', ['M', 'I'])->nullable();
            $table->string('dias_semana', 13)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('modules.rota_transporte_escolar', function (Blueprint $table) {
            $table->dropColumn(['abrangencia', 'dias_semana']);
        });
    }
};
