<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesTransportRecords;
use iEducar\Packages\Transport\View\DetailTable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Testing\TestResponse;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportRecords.php';

class VehicleDetailPageTest extends TestCase
{
    use CreatesTransportRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    public const ROUTE = '/intranet/transporte_veiculo_det.php';

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
    }

    public function test_detail_shows_model_and_outsourced_in_form_order(): void
    {
        $vehicle = $this->vehicleRecord([
            'descricao' => "Ônibus 'escolar'",
            'marca' => "D'Ávila",
            'modelo' => 'Volare W9',
            'terceirizado' => 'S',
            'passageiros' => 44,
        ]);

        $this->detail($vehicle)
            ->assertSee("Ônibus 'escolar'", false)
            ->assertSee("D'Ávila", false)
            ->assertSeeInOrder(['Marca', "D'Ávila", 'Modelo', 'Volare W9', 'Ano fabricação'], false)
            ->assertSeeInOrder(['Limite de passageiros', '44'], false)
            ->assertSeeInOrder(['Adaptado para pessoas com necessidades especiais', 'Terceirizado', 'Sim', 'Ativo'], false);
    }

    public function test_detail_shows_dash_for_empty_model_and_no_for_not_outsourced(): void
    {
        $vehicle = $this->vehicleRecord(['modelo' => null, 'terceirizado' => 'N']);

        $this->detail($vehicle)
            ->assertSeeInOrder(['Modelo', '-', 'Ano fabricação'], false)
            ->assertSeeInOrder(['Terceirizado', 'Não', 'Ativo'], false);
    }

    public function test_detail_lists_routes_where_the_vehicle_is_used(): void
    {
        $vehicle = $this->vehicleRecord();
        $gameleira = $this->transportRouteRecord('Rota da Gameleira ENS008', 2026);
        $acude = $this->transportRouteRecord('Pinto / Açude ENS008', 2025);
        $this->itineraryStopRecord($gameleira, $vehicle, 'I', 1);
        $this->itineraryStopRecord($gameleira, $vehicle, 'I', 2);
        $this->itineraryStopRecord($gameleira, $vehicle, 'V', 3);
        $this->itineraryStopRecord($acude, $vehicle, 'I', 1);

        $response = $this->detail($vehicle)
            ->assertSeeInOrder(['Itinerários', '<div style="width: 0; min-width: 100%; overflow-x: auto;"><table>', 'Ano', 'Rota', 'Sentido', '</table></div>'], false)
            ->assertSeeInOrder([
                '2026', 'Rota da Gameleira ENS008', 'Ida', 'transporte_itinerario_cad.php?cod_rota=' . $gameleira,
                '2026', 'Rota da Gameleira ENS008', 'Volta', 'transporte_itinerario_cad.php?cod_rota=' . $gameleira,
                '2025', 'Pinto / Açude ENS008', 'Ida', 'transporte_itinerario_cad.php?cod_rota=' . $acude,
            ], false)
            ->assertDontSee('Veículo não utilizado em nenhum itinerário.');

        $this->assertSame(3, substr_count($response->getContent(), 'Editar itinerário'));
    }

    public function test_routes_table_uses_the_spaced_layout_with_header_and_content_aligned_left(): void
    {
        $vehicle = $this->vehicleRecord();
        $this->itineraryStopRecord($this->transportRouteRecord('Rota <i>Gameleira</i> ENS013', 2026), $vehicle, 'I', 1);

        $this->detail($vehicle)
            ->assertSeeInOrder([
                '<div style="width: 0; min-width: 100%; overflow-x: auto;"><table><tr>',
                '<td bgcolor="#ccdce6" role="columnheader" style="' . DetailTable::CELL_STYLE . '"><b>Ano</b></td>',
                '<td bgcolor="#ccdce6" role="columnheader" style="' . DetailTable::CELL_STYLE . '"></td></tr>',
                '<td bgcolor="#f5f9fd" style="' . DetailTable::CELL_STYLE . '">2026</td>',
                'Rota &lt;i&gt;Gameleira&lt;/i&gt; ENS013',
            ], false)
            ->assertDontSee('<tr align="center">', false);
    }

    public function test_detail_shows_empty_message_when_vehicle_is_not_used(): void
    {
        $vehicle = $this->vehicleRecord();

        $this->detail($vehicle)
            ->assertSeeInOrder(['Itinerários', 'Veículo não utilizado em nenhum itinerário.'])
            ->assertDontSee('Editar itinerário');
    }

    private function detail(int $vehicle): TestResponse
    {
        return $this->get(self::ROUTE . '?cod_veiculo=' . $vehicle)->assertSuccessful();
    }
}
