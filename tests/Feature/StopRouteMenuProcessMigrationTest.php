<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use App\Menu;
use App\Models\LegacyUser;
use Database\Factories\MenuFactory;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use iEducar\Packages\Transport\Tests\SendsStopApiRequests;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Api\DiarioApiRequestTestTrait;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';
require_once __DIR__ . '/../SendsStopApiRequests.php';

class StopRouteMenuProcessMigrationTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions, DiarioApiRequestTestTrait, SendsStopApiRequests;

    private const MIGRATION = __DIR__ . '/../../database/migrations/2026_11_16_100000_fix_stop_and_route_menu_processes.php';

    private const STOPS_LINK = '/intranet/transporte_ponto_lst.php';

    private const ROUTES_LINK = '/intranet/transporte_rota_lst.php';

    private const SCREEN_PROCESSES = ['stops' => 21239, 'routes' => 21238];

    private const ABILITIES = ['view', 'modify', 'edit', 'remove'];

    private const FULL_ACCESS = ['visualiza' => 1, 'cadastra' => 1, 'edita' => 1, 'exclui' => 1];

    private const VIEW_AND_EDIT = ['visualiza' => 1, 'cadastra' => 0, 'edita' => 1, 'exclui' => 0];

    private const VIEW_CREATE_AND_REMOVE = ['visualiza' => 1, 'cadastra' => 1, 'edita' => 0, 'exclui' => 1];

    private Menu $stopsMenu;

    private Menu $routesMenu;

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];

        $this->stopsMenu = Menu::query()->where('link', self::STOPS_LINK)->sole();
        $this->routesMenu = Menu::query()->where('link', self::ROUTES_LINK)->sole();

        $this->setMenus(stopsProcess: self::SCREEN_PROCESSES['routes'], routesProcess: self::SCREEN_PROCESSES['stops'], stopsDescription: 'Rotas do transporte', routesDescription: 'Pontos do transporte');
    }

    public function test_up_links_each_menu_to_the_process_of_its_screen(): void
    {
        $otherMenus = $this->otherMenus();

        $this->migration()->up();

        $this->assertSame(['Pontos', self::STOPS_LINK, 21239, 'Pontos do transporte'], $this->menuSummary($this->stopsMenu));
        $this->assertSame(['Rotas', self::ROUTES_LINK, 21238, 'Rotas do transporte'], $this->menuSummary($this->routesMenu));
        $this->assertSame($otherMenus, $this->otherMenus());
    }

    public static function permissionsOnEachMenu(): array
    {
        return [
            'stop screen through the menu labelled Rotas' => [null, self::FULL_ACCESS],
            'route screen through the menu labelled Pontos' => [self::FULL_ACCESS, null],
            'different flags on each menu' => [self::VIEW_AND_EDIT, self::VIEW_CREATE_AND_REMOVE],
        ];
    }

    #[DataProvider('permissionsOnEachMenu')]
    public function test_up_keeps_the_access_of_each_user_type_to_each_screen(?array $stopsMenuFlags, ?array $routesMenuFlags): void
    {
        $user = $this->userWithPermissions($stopsMenuFlags, $routesMenuFlags);
        $access = $this->screenAccess($user);

        $this->migration()->up();

        $this->assertSame($access, $this->screenAccess($user));
        $this->assertSame(['stops' => $routesMenuFlags, 'routes' => $stopsMenuFlags], $this->menuPermissions($user));
    }

    public function test_up_moves_the_stop_screen_permission_to_the_menu_labelled_pontos(): void
    {
        $user = $this->userWithPermissions(null, self::FULL_ACCESS);

        $this->assertSame(['stops' => true, 'routes' => false], $this->canModify($user));

        $this->migration()->up();

        $this->assertSame(['stops' => true, 'routes' => false], $this->canModify($user));
        $this->assertSame(['stops' => self::FULL_ACCESS, 'routes' => null], $this->menuPermissions($user));
    }

    public function test_running_up_twice_does_not_swap_back(): void
    {
        $user = $this->userWithPermissions(self::VIEW_AND_EDIT, self::VIEW_CREATE_AND_REMOVE);

        $this->migration()->up();
        $state = $this->state($user);

        $this->migration()->up();

        $this->assertSame($state, $this->state($user));
    }

    public function test_up_changes_nothing_when_the_menus_already_have_the_screen_processes(): void
    {
        $this->setMenus(stopsProcess: self::SCREEN_PROCESSES['stops'], routesProcess: self::SCREEN_PROCESSES['routes'], stopsDescription: 'Pontos do transporte', routesDescription: 'Rotas do transporte');
        $user = $this->userWithPermissions(self::VIEW_AND_EDIT, self::VIEW_CREATE_AND_REMOVE);
        $state = $this->state($user);

        $this->migration()->up();

        $this->assertSame($state, $this->state($user));
    }

    public function test_up_changes_nothing_when_another_menu_has_the_same_link(): void
    {
        MenuFactory::new()->create(['link' => self::STOPS_LINK, 'process' => 99901]);
        $user = $this->userWithPermissions(self::VIEW_AND_EDIT, self::VIEW_CREATE_AND_REMOVE);
        $state = $this->state($user);

        $this->migration()->up();

        $this->assertSame($state, $this->state($user));
    }

    public function test_down_restores_the_previous_menus_and_permissions(): void
    {
        $user = $this->userWithPermissions(self::VIEW_AND_EDIT, self::VIEW_CREATE_AND_REMOVE);
        $otherUser = $this->userWithPermissions(null, self::FULL_ACCESS);
        $state = [$this->state($user), $this->state($otherUser)];

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame($state, [$this->state($user), $this->state($otherUser)]);
    }

    public function test_after_up_the_user_with_the_stop_screen_permission_still_saves_stops_through_the_api(): void
    {
        $this->skipWhenControllerIsTaken('PontoController', \ApiCoreController::class);
        $user = $this->userWithPermissions(null, self::FULL_ACCESS);

        $this->migration()->up();

        $response = $this->stopApiRequest($user, 'post', $this->stopFields())
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertDatabaseHas('modules.ponto_transporte_escolar', ['cod_ponto_transporte_escolar' => (int) $response->json('id'), 'descricao' => 'Praça ENS009']);
    }

    public function test_after_up_the_user_with_only_the_route_screen_permission_is_still_refused_by_the_stop_api(): void
    {
        $this->skipWhenControllerIsTaken('PontoController', \ApiCoreController::class);
        $user = $this->userWithPermissions(self::FULL_ACCESS, null);
        $stops = DB::table('modules.ponto_transporte_escolar')->count();

        $this->migration()->up();

        $this->stopApiRequest($user, 'post', $this->stopFields())
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonPath('msgs.0.msg', "Usuário sem permissão para cadastrar 'ponto'.");

        $this->assertSame($stops, DB::table('modules.ponto_transporte_escolar')->count());
        $this->assertSame(['stops' => false, 'routes' => true], $this->canModify($user));
    }

    private function migration(): Migration
    {
        return require self::MIGRATION;
    }

    private function setMenus(int $stopsProcess, int $routesProcess, string $stopsDescription, string $routesDescription): void
    {
        Menu::query()->whereKey($this->stopsMenu->getKey())->update(['process' => $stopsProcess, 'description' => $stopsDescription]);
        Menu::query()->whereKey($this->routesMenu->getKey())->update(['process' => $routesProcess, 'description' => $routesDescription]);
    }

    private function userWithPermissions(?array $stopsMenuFlags, ?array $routesMenuFlags): LegacyUser
    {
        return $this->userWithMenuFlags([
            $this->stopsMenu->getKey() => $stopsMenuFlags,
            $this->routesMenu->getKey() => $routesMenuFlags,
        ]);
    }

    private function screenAccess(LegacyUser $user): array
    {
        $gate = Gate::forUser($user);
        $access = [];

        foreach (self::SCREEN_PROCESSES as $screen => $process) {
            foreach (self::ABILITIES as $ability) {
                $access[$screen][$ability] = $gate->allows($ability, $process);
            }
        }

        return $access;
    }

    private function canModify(LegacyUser $user): array
    {
        return array_map(static fn (array $abilities) => $abilities['modify'], $this->screenAccess($user));
    }

    private function menuPermissions(LegacyUser $user): array
    {
        return [
            'stops' => $this->menuFlags($user, $this->stopsMenu),
            'routes' => $this->menuFlags($user, $this->routesMenu),
        ];
    }

    private function menuFlags(LegacyUser $user, Menu $menu): ?array
    {
        $flags = DB::table('pmieducar.menu_tipo_usuario')
            ->where('ref_cod_tipo_usuario', $user->ref_cod_tipo_usuario)
            ->where('menu_id', $menu->getKey())
            ->first(array_keys(self::FULL_ACCESS));

        return $flags === null ? null : array_map('intval', (array) $flags);
    }

    private function menuSummary(Menu $menu): array
    {
        $menu = $menu->fresh();

        return [$menu->title, $menu->link, (int) $menu->process, $menu->description];
    }

    private function otherMenus(): array
    {
        return Menu::query()
            ->whereKeyNot([$this->stopsMenu->getKey(), $this->routesMenu->getKey()])
            ->orderBy('id')
            ->get(['id', 'old', 'process', 'description', 'link'])
            ->toArray();
    }

    private function state(LegacyUser $user): array
    {
        return [
            $this->menuSummary($this->stopsMenu),
            $this->menuSummary($this->routesMenu),
            $this->menuPermissions($user),
            $this->screenAccess($user),
        ];
    }
}
