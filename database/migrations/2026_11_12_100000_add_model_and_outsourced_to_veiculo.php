<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules.veiculo', function (Blueprint $table) {
            $table->string('modelo', 50)->nullable();
            $table->char('terceirizado', 1)->default('N');
        });
    }

    public function down(): void
    {
        Schema::table('modules.veiculo', function (Blueprint $table) {
            $table->dropColumn(['modelo', 'terceirizado']);
        });
    }
};
