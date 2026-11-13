<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules.rota_transporte_escolar', function (Blueprint $table) {
            $table->char('abrangencia', 1)->nullable();
            $table->string('dias_semana', 13)->nullable();
        });

        DB::statement("ALTER TABLE modules.rota_transporte_escolar ADD CONSTRAINT rota_transporte_escolar_abrangencia_check CHECK (abrangencia IN ('M', 'I'))");
    }

    public function down(): void
    {
        Schema::table('modules.rota_transporte_escolar', function (Blueprint $table) {
            $table->dropColumn(['abrangencia', 'dias_semana']);
        });
    }
};
