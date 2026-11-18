<?php

use iEducar\Packages\Transport\Database\TransportUserYearDuplicates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->dropUnique(TransportUserYearDuplicates::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        TransportUserYearDuplicates::abortWhenAny();

        Schema::table('modules.pessoa_transporte', function (Blueprint $table) {
            $table->unique(['ref_idpes', 'ano'], TransportUserYearDuplicates::UNIQUE_INDEX);
        });
    }
};
