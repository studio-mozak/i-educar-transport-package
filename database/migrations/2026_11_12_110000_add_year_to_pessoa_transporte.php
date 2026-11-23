<?php

use iEducar\Packages\Transport\Database\TransportUserYearDuplicates;
use iEducar\Packages\Transport\Models\TransportRoute;
use iEducar\Packages\Transport\Models\TransportUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->smallInteger('ano')->nullable();
        });

        TransportUser::query()->update([
            'ano' => TransportRoute::query()
                ->select('ano')
                ->whereColumn('cod_rota_transporte_escolar', 'modules.pessoa_transporte.ref_cod_rota_transporte_escolar'),
        ]);

        TransportUserYearDuplicates::abortWhenAny();

        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->smallInteger('ano')->nullable(false)->change();
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
