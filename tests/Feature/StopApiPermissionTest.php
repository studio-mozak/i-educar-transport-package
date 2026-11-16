<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use App\Models\LegacyUser;
use App\Models\Place;
use Database\Factories\MenuFactory;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use iEducar\Packages\Transport\Tests\SendsStopApiRequests;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Api\DiarioApiRequestTestTrait;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';
require_once __DIR__ . '/../SendsStopApiRequests.php';

class StopApiPermissionTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions, DiarioApiRequestTestTrait, SendsStopApiRequests;

    public const STOP_PROCESS = 21239;

    public const OLD_API_PROCESS = 578;

    private const FULL_ACCESS = ['visualiza' => 1, 'cadastra' => 1, 'edita' => 1, 'exclui' => 1];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenControllerIsTaken('PontoController', \ApiCoreController::class);

        $_GET = [];
        $_POST = [];
    }

    public static function usersWithoutPermission(): array
    {
        return [
            'only the old api process' => [null, self::FULL_ACCESS],
            'stop screen read only' => [['visualiza' => 1, 'cadastra' => 0, 'edita' => 0, 'exclui' => 0], []],
        ];
    }

    #[DataProvider('usersWithoutPermission')]
    public function test_post_without_permission_saves_nothing(?array $stopFlags, array $oldProcessFlags): void
    {
        $user = $this->user($stopFlags, $oldProcessFlags);
        $stops = DB::table('modules.ponto_transporte_escolar')->count();
        $places = Place::query()->count();

        $this->stopApiRequest($user, 'post', $this->stopFields())
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonPath('msgs.0.msg', "Usuário sem permissão para cadastrar 'ponto'.")
            ->assertJsonMissingPath('id');

        $this->assertSame($stops, DB::table('modules.ponto_transporte_escolar')->count());
        $this->assertSame($places, Place::query()->count());
    }

    #[DataProvider('usersWithoutPermission')]
    public function test_put_without_permission_changes_nothing(?array $stopFlags, array $oldProcessFlags): void
    {
        $user = $this->user($stopFlags, $oldProcessFlags);
        $stop = $this->stopWithPlace();
        $before = $this->stop($stop);
        $place = Place::query()->findOrFail($before['idlog'])->toArray();

        $this->stopApiRequest($user, 'put', array_merge($this->stopFields(), ['id' => (string) $stop, 'desc' => 'Praça alterada ENS009', 'latitude' => '', 'longitude' => '']))
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonPath('msgs.0.msg', "Usuário sem permissão para editar 'ponto'.");

        $this->assertSame($before, $this->stop($stop));
        $this->assertSame($place, Place::query()->findOrFail($before['idlog'])->toArray());
    }

    #[DataProvider('usersWithoutPermission')]
    public function test_delete_without_permission_keeps_the_stop(?array $stopFlags, array $oldProcessFlags): void
    {
        $user = $this->user($stopFlags, $oldProcessFlags);
        $stop = $this->stopWithPlace();

        $this->stopApiRequest($user, 'delete', ['id' => (string) $stop])
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonPath('msgs.0.msg', "Usuário sem permissão para excluir 'ponto'.");

        $this->assertDatabaseHas('modules.ponto_transporte_escolar', ['cod_ponto_transporte_escolar' => $stop]);
    }

    public function test_post_with_permission_on_the_stop_screen_saves_the_stop(): void
    {
        $response = $this->stopApiRequest($this->user(['visualiza' => 1, 'cadastra' => 1, 'edita' => 0, 'exclui' => 0]), 'post', $this->stopFields())
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $stop = (int) $response->json('id');

        $this->assertSame('Praça ENS009', $this->stop($stop)['descricao']);
        $this->assertSame(['-5.0912', '-42.8011'], $this->stopCoordinates($stop));
    }

    public function test_put_with_permission_on_the_stop_screen_saves_the_change(): void
    {
        $stop = $this->stopWithPlace();

        $this->stopApiRequest($this->user(['visualiza' => 1, 'cadastra' => 0, 'edita' => 1, 'exclui' => 0]), 'put', array_merge($this->stopFields(), ['id' => (string) $stop, 'desc' => 'Praça alterada ENS009', 'latitude' => '-5.103522', 'longitude' => '-42.830093']))
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame('Praça alterada ENS009', $this->stop($stop)['descricao']);
        $this->assertSame(['-5.103522', '-42.830093'], $this->stopCoordinates($stop));
    }

    public function test_delete_with_permission_on_the_stop_screen_reaches_the_itinerary_check(): void
    {
        $stop = $this->stopWithPlace();
        $this->itineraryStopRecord($this->routeRecord('Rota da Gameleira ENS009', 2026), 1, $stop, null, '06:30', 'I');

        $this->stopApiRequest($this->user(['visualiza' => 1, 'cadastra' => 0, 'edita' => 0, 'exclui' => 1]), 'delete', ['id' => (string) $stop])
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonPath('msgs.0.msg', 'Não é possível excluir um ponto que está vinculado a um itinerário.');

        $this->assertDatabaseHas('modules.ponto_transporte_escolar', ['cod_ponto_transporte_escolar' => $stop]);
    }

    private function user(?array $stopFlags, array $oldProcessFlags = []): LegacyUser
    {
        $flagsByProcess = array_filter([self::STOP_PROCESS => $stopFlags, self::OLD_API_PROCESS => $oldProcessFlags]);
        $menuIds = array_map(static fn (int $process) => MenuFactory::new()->create(['process' => $process])->getKey(), array_keys($flagsByProcess));

        return $this->userWithMenuFlags(array_combine($menuIds, $flagsByProcess));
    }

    private function stop(int $stop): array
    {
        return (array) DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->first();
    }
}
