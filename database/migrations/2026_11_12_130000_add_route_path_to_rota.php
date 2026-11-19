<?php

use App\Setting;
use App\SettingCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY_SETTING = 'transport.openrouteservice_key';

    private const CATEGORY = 'Transporte escolar';

    public function up(): void
    {
        Schema::table('modules.rota_transporte_escolar', function (Blueprint $table) {
            $table->double('km_ida')->nullable();
            $table->double('km_volta')->nullable();
            $table->jsonb('trajeto_ida')->nullable();
            $table->jsonb('trajeto_volta')->nullable();
            $table->string('trajeto_hash', 64)->nullable();
            $table->timestamp('trajeto_calculado_em')->nullable();
        });

        $category = SettingCategory::query()->firstOrCreate(['name' => self::CATEGORY]);

        Setting::query()->firstOrCreate(['key' => self::KEY_SETTING], [
            'setting_category_id' => $category->getKey(),
            'value' => null,
            'type' => Setting::TYPE_STRING,
            'description' => 'Chave do serviço de rotas (OpenRouteService)',
            'hint' => 'Usada para traçar as rotas do transporte escolar no mapa e calcular a quilometragem. Crie a chave gratuita em openrouteservice.org.',
        ]);
    }

    public function down(): void
    {
        Setting::query()->where('key', self::KEY_SETTING)->delete();

        SettingCategory::query()->where('name', self::CATEGORY)->whereDoesntHave('settings')->delete();

        Schema::table('modules.rota_transporte_escolar', function (Blueprint $table) {
            $table->dropColumn(['km_ida', 'km_volta', 'trajeto_ida', 'trajeto_volta', 'trajeto_hash', 'trajeto_calculado_em']);
        });
    }
};
