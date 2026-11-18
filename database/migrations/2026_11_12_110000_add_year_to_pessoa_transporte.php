<?php

use iEducar\Packages\Transport\Database\TransportUserYearDuplicates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->smallInteger('ano')->nullable();
        });

        DB::update('
            UPDATE modules.pessoa_transporte pt
            SET ano = r.ano
            FROM modules.rota_transporte_escolar r
            WHERE r.cod_rota_transporte_escolar = pt.ref_cod_rota_transporte_escolar
        ');

        TransportUserYearDuplicates::abortWhenAny();

        DB::statement('ALTER TABLE modules.pessoa_transporte ALTER COLUMN ano SET NOT NULL');

        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->unique(['ref_idpes', 'ano'], TransportUserYearDuplicates::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->dropUnique(TransportUserYearDuplicates::UNIQUE_INDEX);
            $table->dropColumn('ano');
        });
    }
};
