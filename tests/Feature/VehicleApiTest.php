<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use Closure;
use iEducar\Packages\Transport\Tests\CreatesDriverRecords;
use iEducar\Packages\Transport\Tests\CreatesTransportRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Api\DiarioApiRequestTestTrait;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesDriverRecords.php';
require_once __DIR__ . '/../CreatesTransportRecords.php';

class VehicleApiTest extends TestCase
{
    use CreatesDriverRecords, CreatesTransportRecords, DatabaseTransactions, DiarioApiRequestTestTrait;

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

    public static function searchQueries(): array
    {
        return [
            'numeric search by code' => [fn (int $vehicle) => (string) $vehicle],
            'text search by description' => [fn (int $vehicle) => 'Microônibus Timon'],
        ];
    }

    #[DataProvider('searchQueries')]
    public function test_search_shows_description_and_plate_when_the_vehicle_has_no_driver(Closure $query): void
    {
        $vehicle = $this->vehicleRecord(['descricao' => 'TESTE Microônibus Timon ENS013', 'placa' => 'TST1A23']);

        $this->search($query($vehicle))->assertJsonPath('result.' . $vehicle, $vehicle . ' - TESTE Microônibus Timon ENS013, Placa: TST1A23');
    }

    #[DataProvider('searchQueries')]
    public function test_search_keeps_the_driver_when_the_vehicle_has_one(Closure $query): void
    {
        $vehicle = $this->vehicleRecord([
            'descricao' => 'TESTE Microônibus Timon ENS013',
            'placa' => 'TST1A23',
            'ref_cod_motorista' => $this->driverRecord('Motorista ENS013', $this->transportCompanyRecord()),
        ]);

        $this->search($query($vehicle))->assertJsonPath('result.' . $vehicle, $vehicle . ' - TESTE Microônibus Timon ENS013, Placa: TST1A23, Motorista: Motorista ENS013');
    }

    private function search(string $query): TestResponse
    {
        return $this->getResource(self::ROUTE, ['oper' => 'get', 'resource' => 'veiculo-search', 'query' => $query])->assertSuccessful();
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
