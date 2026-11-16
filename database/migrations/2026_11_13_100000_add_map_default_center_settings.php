<?php

use App\Setting;
use App\SettingCategory;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const CATEGORY = 'Transporte escolar';

    private const HINT = 'Usada para abrir os mapas do transporte escolar quando nenhum ponto tem localização. Em graus decimais (ex.: -5.091234). Deixe as duas em branco para abrir no Brasil.';

    private const SETTINGS = [
        'transport.map_default_latitude' => 'Latitude do centro padrão do mapa',
        'transport.map_default_longitude' => 'Longitude do centro padrão do mapa',
    ];

    public function up(): void
    {
        $category = SettingCategory::query()->firstOrCreate(['name' => self::CATEGORY]);

        foreach (self::SETTINGS as $key => $description) {
            Setting::query()->firstOrCreate(['key' => $key], [
                'setting_category_id' => $category->getKey(),
                'value' => null,
                'type' => Setting::TYPE_STRING,
                'description' => $description,
                'hint' => self::HINT,
            ]);
        }
    }

    public function down(): void
    {
        Setting::query()->whereIn('key', array_keys(self::SETTINGS))->delete();

        SettingCategory::query()->where('name', self::CATEGORY)->whereDoesntHave('settings')->delete();
    }
};
