<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use Database\Factories\LegacyUserFactory;
use iEducar\Packages\Transport\Tests\CreatesTransportUserRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Api\DiarioApiRequestTestTrait;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportUserRecords.php';

class RouteYearChangeTest extends TestCase
{
    use CreatesTransportUserRecords, DatabaseTransactions, DiarioApiRequestTestTrait;

    public const ROUTE = '/module/Api/Rota';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenControllerIsTaken('RotaController', \ApiCoreController::class);
    }

    public function test_put_blocks_changing_the_year_of_a_route_with_users(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS011', 2026);
        $this->transportUserRecord($this->personRecord(), $route);

        $response = $this->postResource(self::ROUTE, $this->routeForm($route, ['ano' => 2027]))->assertSuccessful();

        $this->assertTrue($response->json('any_error_msg'));
        $this->assertSame('Não é possível alterar o ano de uma rota com usuários vinculados.', $response->json('msgs.0.msg'));
        $this->assertSame(2026, (int) $this->routeYear($route));
    }

    public function test_put_keeps_saving_a_route_with_users_when_the_year_does_not_change(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS011', 2026);
        $this->transportUserRecord($this->personRecord(), $route);

        $this->postResource(self::ROUTE, $this->routeForm($route, ['ano' => 2026, 'desc' => 'Rota da Gameleira nova ENS011']))
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame(
            'Rota da Gameleira nova ENS011',
            DB::table('modules.rota_transporte_escolar')->where('cod_rota_transporte_escolar', $route)->value('descricao')
        );
    }

    public function test_put_changes_the_year_of_a_route_without_users(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS011', 2026);

        $this->postResource(self::ROUTE, $this->routeForm($route, ['ano' => 2027]))
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame(2027, (int) $this->routeYear($route));
    }

    public function test_delete_of_a_route_with_users_stays_blocked(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS011', 2026);
        $this->transportUserRecord($this->personRecord(), $route);

        $this->postResource(self::ROUTE, ['oper' => 'delete', 'resource' => 'rota', 'id' => $route])
            ->assertSuccessful()
            ->assertJsonPath('msgs.0.msg', 'Não é possível excluir uma rota que está vinculada a uma pessoa.');

        $this->assertNotNull($this->routeYear($route));
    }

    public function test_route_copy_does_not_copy_transport_users(): void
    {
        $company = $this->companyRecord();
        $route = $this->routeRecord('Rota da Gameleira ENS011', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        DB::table('modules.itinerario_transporte_escolar')->insert([
            'ref_cod_rota_transporte_escolar' => $route,
            'seq' => 1,
            'ref_cod_ponto_transporte_escolar' => $this->stopRecord('Ponto ENS011'),
            'tipo' => 'I',
        ]);
        $person = $this->personRecord();
        $this->transportUserRecord($person, $route);

        $this->actingAs(LegacyUserFactory::new()->admin()->create())
            ->post('/intranet/transporte_copia_rotas.php', [
                'tipoacao' => 'Novo',
                'ref_cod_empresa_transporte_escolar' => (string) $company,
                'ano_orig' => 2026,
                'ano_dest' => 2099,
            ])
            ->assertSuccessful();

        $copiedRoutes = DB::table('modules.rota_transporte_escolar')
            ->where('ref_cod_empresa_transporte_escolar', $company)
            ->where('ano', 2099)
            ->pluck('cod_rota_transporte_escolar');

        $this->assertCount(1, $copiedRoutes);
        $this->assertSame(1, DB::table('modules.itinerario_transporte_escolar')->where('ref_cod_rota_transporte_escolar', $copiedRoutes->first())->count());
        $this->assertSame(0, DB::table('modules.pessoa_transporte')->whereIn('ref_cod_rota_transporte_escolar', $copiedRoutes)->count());
        $this->assertSame(1, $this->transportUserCount($person));
    }

    private function routeForm(int $route, array $overrides): array
    {
        $saved = DB::table('modules.rota_transporte_escolar')->where('cod_rota_transporte_escolar', $route)->first();

        return array_merge([
            'oper' => 'put',
            'resource' => 'rota',
            'id' => $route,
            'desc' => $saved->descricao,
            'pessoaj_id' => $saved->ref_idpes_destino,
            'ano' => $saved->ano,
            'tipo_rota' => $saved->tipo_rota,
            'empresa_id' => $this->companyRecord(),
        ], $overrides);
    }

    private function routeYear(int $route): ?int
    {
        return DB::table('modules.rota_transporte_escolar')->where('cod_rota_transporte_escolar', $route)->value('ano');
    }
}
