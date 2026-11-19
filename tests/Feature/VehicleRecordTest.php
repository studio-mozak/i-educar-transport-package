<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesTransportRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportRecords.php';

class VehicleRecordTest extends TestCase
{
    use CreatesTransportRecords, DatabaseTransactions;

    public function test_creates_vehicle_with_model_and_outsourced(): void
    {
        $vehicle = $this->newVehicle();
        $vehicle->modelo = 'Volare W9';
        $vehicle->terceirizado = 'S';

        $id = $vehicle->cadastra();

        $this->assertDatabaseHas('modules.veiculo', [
            'cod_veiculo' => $id,
            'modelo' => 'Volare W9',
            'terceirizado' => 'S',
        ]);
    }

    public function test_vehicle_is_not_outsourced_by_default(): void
    {
        $id = $this->newVehicle()->cadastra();

        $this->assertDatabaseHas('modules.veiculo', [
            'cod_veiculo' => $id,
            'modelo' => null,
            'terceirizado' => 'N',
        ]);
    }

    public function test_edit_updates_model_outsourced_and_passengers(): void
    {
        $id = $this->vehicleRecord(['passageiros' => 40, 'modelo' => 'Volare W8', 'terceirizado' => 'N']);

        $vehicle = $this->newVehicle($id);
        $vehicle->passageiros = '44';
        $vehicle->modelo = 'Volare W9';
        $vehicle->terceirizado = 'S';

        $this->assertTrue($vehicle->edita());
        $this->assertDatabaseHas('modules.veiculo', [
            'cod_veiculo' => $id,
            'passageiros' => 44,
            'modelo' => 'Volare W9',
            'terceirizado' => 'S',
        ]);
    }

    public function test_create_accepts_apostrophes_in_free_text_fields(): void
    {
        $vehicle = $this->newVehicle();
        $this->fillWithApostrophes($vehicle);

        $id = $vehicle->cadastra();

        $this->assertIsNumeric($id);
        $this->assertVehicleHasApostrophes($id);
    }

    public function test_edit_accepts_apostrophes_in_free_text_fields(): void
    {
        $id = $this->vehicleRecord();
        $vehicle = $this->newVehicle($id);
        $this->fillWithApostrophes($vehicle);

        $this->assertTrue($vehicle->edita());
        $this->assertVehicleHasApostrophes($id);
    }

    public function test_list_filters_accept_apostrophes(): void
    {
        $id = $this->vehicleRecord([
            'descricao' => "Ônibus 'escolar'",
            'marca' => "D'Ávila",
            'placa' => "ENS'0082",
            'renavam' => "802'456",
            'ativo' => 'S',
        ]);

        $list = (new \clsModulesVeiculo)->lista(null, "Ônibus 'escolar'", "ENS'0082", "802'456", null, null, "D'Ávila", 'S');

        $this->assertSame([$id], array_map('intval', array_column($list, 'cod_veiculo')));
    }

    public function test_list_filters_by_driver_name_with_apostrophe_without_breaking(): void
    {
        $this->vehicleRecord();

        $this->assertFalse((new \clsModulesVeiculo)->lista(null, null, null, null, "Zé D'Ávila ENS008"));
    }

    public function test_list_filter_by_active_status_with_apostrophe_without_breaking(): void
    {
        $this->vehicleRecord();

        $this->assertFalse((new \clsModulesVeiculo)->lista(null, null, null, null, null, null, null, "S'"));
    }

    private function newVehicle(?int $id = null): \clsModulesVeiculo
    {
        $vehicle = new \clsModulesVeiculo;
        $vehicle->cod_veiculo = $id;
        $vehicle->descricao = 'Ônibus 01';
        $vehicle->renavam = '12345678901';
        $vehicle->passageiros = '40';
        $vehicle->malha = 'R';
        $vehicle->ref_cod_tipo_veiculo = $this->vehicleTypeId();
        $vehicle->exclusivo_transporte_escolar = 'S';
        $vehicle->adaptado_necessidades_especiais = 'N';
        $vehicle->ativo = 'S';
        $vehicle->ref_cod_empresa_transporte_escolar = $this->transportCompanyRecord();

        return $vehicle;
    }

    private function fillWithApostrophes(\clsModulesVeiculo $vehicle): void
    {
        $vehicle->descricao = "Ônibus 'escolar'";
        $vehicle->marca = "D'Ávila";
        $vehicle->modelo = "Volare 'W9'";
        $vehicle->placa = "ABC'1234";
        $vehicle->renavam = "123'456";
        $vehicle->chassi = "9BW'ZZZ377VT004251";
        $vehicle->ativo = 'N';
        $vehicle->descricao_inativo = "Parado na oficina 'Zé'";
    }

    private function assertVehicleHasApostrophes(int $id): void
    {
        $record = DB::table('modules.veiculo')->where('cod_veiculo', $id)->first();

        $this->assertSame("Ônibus 'escolar'", $record->descricao);
        $this->assertSame("D'Ávila", $record->marca);
        $this->assertSame("Volare 'W9'", $record->modelo);
        $this->assertSame("ABC'1234", $record->placa);
        $this->assertSame("123'456", $record->renavam);
        $this->assertSame("9BW'ZZZ377VT004251", $record->chassi);
        $this->assertSame("Parado na oficina 'Zé'", trim($record->descricao_inativo));
    }
}
