<?php

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
                ->whereColumn('cod_rota_transporte_escolar', 'modules.pessoa_transporte.ref_cod_rota_transporte_escolar')
                ->toBase(),
        ]);

        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->smallInteger('ano')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->dropColumn('ano');
        });
    }
};
