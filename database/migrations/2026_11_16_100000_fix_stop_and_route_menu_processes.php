<?php

use App\Menu;
use App\Models\LegacyUserType;
use App\Services\MenuCacheService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STOPS_LINK = '/intranet/transporte_ponto_lst.php';

    private const ROUTES_LINK = '/intranet/transporte_rota_lst.php';

    private const STOPS_PROCESS = 21239;

    private const ROUTES_PROCESS = 21238;

    private const PERMISSIONS_TABLE = 'pmieducar.menu_tipo_usuario';

    public function up(): void
    {
        $this->swapWhenMenusHave(stopsProcess: self::ROUTES_PROCESS, routesProcess: self::STOPS_PROCESS);
    }

    public function down(): void
    {
        $this->swapWhenMenusHave(stopsProcess: self::STOPS_PROCESS, routesProcess: self::ROUTES_PROCESS);
    }

    private function swapWhenMenusHave(int $stopsProcess, int $routesProcess): void
    {
        $stops = $this->singleMenuWithLink(self::STOPS_LINK);
        $routes = $this->singleMenuWithLink(self::ROUTES_LINK);

        if ($stops === null || $routes === null || (int) $stops->process !== $stopsProcess || (int) $routes->process !== $routesProcess) {
            return;
        }

        DB::transaction(function () use ($stops, $routes) {
            Menu::query()->whereKey($stops->getKey())->update(['process' => $routes->process, 'description' => $routes->description]);
            Menu::query()->whereKey($routes->getKey())->update(['process' => $stops->process, 'description' => $stops->description]);

            $this->swapPermissions((int) $stops->getKey(), (int) $routes->getKey());
        });

        $this->flushMenuCache();
    }

    private function singleMenuWithLink(string $link): ?Menu
    {
        $menus = Menu::query()->where('link', $link)->get();

        return $menus->count() === 1 ? $menus->first() : null;
    }

    private function swapPermissions(int $firstMenu, int $secondMenu): void
    {
        $permissions = DB::table(self::PERMISSIONS_TABLE)->whereIn('menu_id', [$firstMenu, $secondMenu])->get();

        DB::table(self::PERMISSIONS_TABLE)->whereIn('menu_id', [$firstMenu, $secondMenu])->delete();

        DB::table(self::PERMISSIONS_TABLE)->insert($permissions->map(static fn (object $permission) => array_merge((array) $permission, [
            'menu_id' => (int) $permission->menu_id === $firstMenu ? $secondMenu : $firstMenu,
        ]))->all());
    }

    private function flushMenuCache(): void
    {
        $cache = app(MenuCacheService::class);

        foreach (LegacyUserType::query()->pluck('cod_tipo_usuario') as $userType) {
            $cache->flushMenuTag($userType);
        }
    }
};
