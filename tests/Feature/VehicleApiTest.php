<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesTransportRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Api\DiarioApiRequestTestTrait;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportRecords.php';

class VehicleApiTest extends TestCase
{
    use CreatesTransportRecords, DatabaseTransactions, DiarioApiRequestTestTrait;

    public const ROUTE = '/module/Api/Veiculo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenVehicleControllerIsTaken(\ApiCoreController::class);
    }

    public function test_get_returns_model_and_outsourced(): void
    {
        $vehicle = $this->vehicleRecord(['modelo' => 'Volare W9', 'terceirizado' => 'S']);

        $this->getResource(self::ROUTE, ['oper' => 'get', 'resource' => 'veiculo', 'id' => $vehicle])
            ->assertSuccessful()
            ->assertJson(['id' => $vehicle, 'modelo' => 'Volare W9', 'terceirizado' => 'S']);
    }

    public function test_post_saves_model_and_outsourced(): void
    {
        $this->postResource(self::ROUTE, $this->formData(['modelo' => 'Volare W9', 'terceirizado' => 'on']))
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertDatabaseHas('modules.veiculo', [
            'descricao' => 'Ônibus ENS008',
            'modelo' => 'Volare W9',
            'terceirizado' => 'S',
        ]);
    }

    public function test_put_saves_passengers_model_and_outsourced(): void
    {
        $vehicle = $this->vehicleRecord(['passageiros' => 40, 'modelo' => 'Volare W8', 'terceirizado' => 'S']);

        $this->postResource(self::ROUTE, $this->formData([
            'oper' => 'put',
            'id' => $vehicle,
            'passageiros' => 44,
            'modelo' => 'Volare W9',
        ]))->assertSuccessful();

        $this->assertDatabaseHas('modules.veiculo', [
            'cod_veiculo' => $vehicle,
            'passageiros' => 44,
            'modelo' => 'Volare W9',
            'terceirizado' => 'N',
        ]);
    }

    private function formData(array $overrides): array
    {
        return array_merge([
            'oper' => 'post',
            'resource' => 'veiculo',
            'descricao' => 'Ônibus ENS008',
            'renavam' => '12345678901',
            'passageiros' => 40,
            'malha' => 'R',
            'tipo' => $this->vehicleTypeId(),
            'exclusivo_transporte_escolar' => 'on',
            'ativo' => 'on',
            'empresa_id' => $this->transportCompanyRecord(),
        ], $overrides);
    }
}
