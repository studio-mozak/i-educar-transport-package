<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesTransportUserRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Api\DiarioApiRequestTestTrait;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportUserRecords.php';

class TransportUserYearTest extends TestCase
{
    use CreatesTransportUserRecords, DatabaseTransactions, DiarioApiRequestTestTrait;

    public const ROUTE = '/module/Api/Pessoatransporte';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenControllerIsTaken('PessoatransporteController', \ApiCoreController::class);
    }

    public function test_post_saves_the_year_of_the_selected_route(): void
    {
        $person = $this->personRecord();
        $route = $this->routeRecord('Rota da Gameleira ENS011', 2026);
        $stop = $this->stopRecord('Escola Municipal X ENS011');

        $this->postResource(self::ROUTE, [
            'oper' => 'post',
            'resource' => 'pessoatransporte',
            'pessoa_id' => $person,
            'rota' => $route,
            'ponto' => $stop,
            'turno' => 1,
        ])->assertSuccessful()->assertJsonPath('any_error_msg', false);

        $this->assertDatabaseHas('modules.pessoa_transporte', [
            'ref_idpes' => $person,
            'ref_cod_rota_transporte_escolar' => $route,
            'ref_cod_ponto_transporte_escolar' => $stop,
            'ano' => 2026,
        ]);
    }

    public function test_post_saves_a_link_in_another_route_of_a_year_that_already_has_one(): void
    {
        $person = $this->personRecord();
        $this->transportUserRecord($person, $this->routeRecord('Rota A ENS016', 2026), ['turno' => '1']);

        $this->assertSecondLinkIsSaved($person, $this->routeRecord('Rota B ENS016', 2026), 1);
    }

    public function test_post_saves_a_link_in_the_same_route_with_another_period(): void
    {
        $person = $this->personRecord();
        $route = $this->routeRecord('Rota A ENS016', 2026);
        $this->transportUserRecord($person, $route, ['turno' => '1']);

        $this->assertSecondLinkIsSaved($person, $route, 2);
    }

    public function test_post_allows_a_link_in_another_year(): void
    {
        $person = $this->personRecord();
        $this->transportUserRecord($person, $this->routeRecord('Rota A ENS011', 2026));
        $nextYearRoute = $this->routeRecord('Rota A ENS011', 2027);

        $this->postResource(self::ROUTE, [
            'oper' => 'post',
            'resource' => 'pessoatransporte',
            'pessoa_id' => $person,
            'rota' => $nextYearRoute,
        ])->assertSuccessful()->assertJsonPath('any_error_msg', false);

        $this->assertSame(2, $this->transportUserCount($person));
        $this->assertDatabaseHas('modules.pessoa_transporte', [
            'ref_idpes' => $person,
            'ref_cod_rota_transporte_escolar' => $nextYearRoute,
            'ano' => 2027,
        ]);
    }

    public function test_put_moves_a_link_to_a_year_that_already_has_another_link(): void
    {
        $person = $this->personRecord();
        $this->transportUserRecord($person, $this->routeRecord('Rota A ENS016', 2026));
        $link = $this->transportUserRecord($person, $this->routeRecord('Rota A ENS016', 2027));
        $routeIn2026 = $this->routeRecord('Rota B ENS016', 2026);

        $this->postResource(self::ROUTE, [
            'oper' => 'put',
            'resource' => 'pessoatransporte',
            'id' => $link,
            'pessoa_id' => $person,
            'rota' => $routeIn2026,
        ])->assertSuccessful()->assertJsonPath('any_error_msg', false);

        $saved = $this->transportUser($link);
        $this->assertSame($routeIn2026, (int) $saved->ref_cod_rota_transporte_escolar);
        $this->assertSame(2026, (int) $saved->ano);
    }

    public function test_put_moves_the_link_to_another_route_of_the_same_year(): void
    {
        $person = $this->personRecord();
        $link = $this->transportUserRecord($person, $this->routeRecord('Rota A ENS011', 2026));
        $sameYearRoute = $this->routeRecord('Rota B ENS011', 2026);

        $this->postResource(self::ROUTE, [
            'oper' => 'put',
            'resource' => 'pessoatransporte',
            'id' => $link,
            'pessoa_id' => $person,
            'rota' => $sameYearRoute,
        ])->assertSuccessful()->assertJsonPath('any_error_msg', false);

        $saved = $this->transportUser($link);
        $this->assertSame($sameYearRoute, (int) $saved->ref_cod_rota_transporte_escolar);
        $this->assertSame(2026, (int) $saved->ano);
    }

    public function test_put_moves_the_link_to_a_free_year_and_updates_the_year(): void
    {
        $person = $this->personRecord();
        $link = $this->transportUserRecord($person, $this->routeRecord('Rota A ENS011', 2026));
        $nextYearRoute = $this->routeRecord('Rota C ENS011', 2027);

        $this->postResource(self::ROUTE, [
            'oper' => 'put',
            'resource' => 'pessoatransporte',
            'id' => $link,
            'pessoa_id' => $person,
            'rota' => $nextYearRoute,
        ])->assertSuccessful()->assertJsonPath('any_error_msg', false);

        $saved = $this->transportUser($link);
        $this->assertSame($nextYearRoute, (int) $saved->ref_cod_rota_transporte_escolar);
        $this->assertSame(2027, (int) $saved->ano);
    }

    public function test_put_without_stop_and_destination_keeps_them(): void
    {
        $person = $this->personRecord();
        $route = $this->routeRecord('Rota A ENS011', 2026);
        $stop = $this->stopRecord('Ponto ENS011');
        $destination = $this->destinationRecord();
        $link = $this->transportUserRecord($person, $route, [
            'ref_cod_ponto_transporte_escolar' => $stop,
            'ref_idpes_destino' => $destination,
        ]);

        $this->postResource(self::ROUTE, [
            'oper' => 'put',
            'resource' => 'pessoatransporte',
            'id' => $link,
            'pessoa_id' => $person,
            'rota' => $route,
            'observacao' => 'Embarca no portão',
        ])->assertSuccessful()->assertJsonPath('any_error_msg', false);

        $saved = $this->transportUser($link);
        $this->assertSame($stop, (int) $saved->ref_cod_ponto_transporte_escolar);
        $this->assertSame($destination, (int) $saved->ref_idpes_destino);
        $this->assertSame('Embarca no portão', $saved->observacao);
    }

    public function test_put_with_empty_stop_and_destination_clears_them(): void
    {
        $person = $this->personRecord();
        $route = $this->routeRecord('Rota A ENS011', 2026);
        $link = $this->transportUserRecord($person, $route, [
            'ref_cod_ponto_transporte_escolar' => $this->stopRecord('Ponto ENS011'),
            'ref_idpes_destino' => $this->destinationRecord(),
        ]);

        $this->postResource(self::ROUTE, [
            'oper' => 'put',
            'resource' => 'pessoatransporte',
            'id' => $link,
            'pessoa_id' => $person,
            'rota' => $route,
            'ponto' => '',
            'pessoaj_id' => '',
        ])->assertSuccessful()->assertJsonPath('any_error_msg', false);

        $saved = $this->transportUser($link);
        $this->assertNull($saved->ref_cod_ponto_transporte_escolar);
        $this->assertNull($saved->ref_idpes_destino);
    }

    public function test_get_returns_the_year(): void
    {
        $link = $this->transportUserRecord($this->personRecord(), $this->routeRecord('Rota A ENS011', 2026));

        $this->getResource(self::ROUTE, ['oper' => 'get', 'resource' => 'pessoatransporte', 'id' => $link])
            ->assertSuccessful()
            ->assertJson(['id' => $link, 'ano' => 2026]);
    }

    private function assertSecondLinkIsSaved(int $person, int $route, int $period): void
    {
        $response = $this->postResource(self::ROUTE, [
            'oper' => 'post',
            'resource' => 'pessoatransporte',
            'pessoa_id' => $person,
            'rota' => $route,
            'turno' => $period,
        ])->assertSuccessful()->assertJsonPath('any_error_msg', false);

        $this->assertSame('Cadastro realizado com sucesso', $response->json('msgs.0.msg'));
        $this->assertSame(2, $this->transportUserCount($person));
        $this->assertDatabaseHas('modules.pessoa_transporte', [
            'cod_pessoa_transporte' => $response->json('id'),
            'ref_idpes' => $person,
            'ref_cod_rota_transporte_escolar' => $route,
            'turno' => (string) $period,
            'ano' => 2026,
        ]);
    }
}
