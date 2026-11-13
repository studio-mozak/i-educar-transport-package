<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'pessoa_transporte_ref_idpes_ano_unique';

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

        $this->abortWhenPersonHasManyLinksInSameYear();

        DB::statement('ALTER TABLE modules.pessoa_transporte ALTER COLUMN ano SET NOT NULL');

        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->unique(['ref_idpes', 'ano'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('ano');
        });
    }

    private function abortWhenPersonHasManyLinksInSameYear(): void
    {
        $duplicates = DB::select('
            SELECT ref_idpes, ano, string_agg(cod_pessoa_transporte::text, \', \' ORDER BY cod_pessoa_transporte) AS links
            FROM modules.pessoa_transporte
            GROUP BY ref_idpes, ano
            HAVING count(*) > 1
            ORDER BY ref_idpes, ano
        ');

        if (empty($duplicates)) {
            return;
        }

        $lines = array_map(
            fn ($duplicate) => "pessoa {$duplicate->ref_idpes}, ano {$duplicate->ano}: vínculos {$duplicate->links}",
            $duplicates
        );

        throw new RuntimeException(
            'Existem pessoas com mais de um vínculo de transporte no mesmo ano. '
            . 'Resolva os vínculos abaixo em Usuários de transporte e rode a migration de novo. '
            . 'Nenhum dado foi alterado. ' . implode('; ', $lines)
        );
    }
};
