<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesTransportRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Testing\TestResponse;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportRecords.php';

class VehicleListPageTest extends TestCase
{
    use CreatesTransportRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    public const ROUTE = '/intranet/transporte_veiculo_lst.php';

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
    }

    public function test_text_filters_with_apostrophes_find_the_vehicle(): void
    {
        $vehicle = $this->vehicleRecord([
            'descricao' => "Ônibus 'escolar' ENS008",
            'marca' => "D'Ávila",
            'placa' => "ENS'0083",
            'renavam' => "803'456",
        ]);

        $this->listing([
            'descricao' => "Ônibus 'escolar' ENS008",
            'marca' => "D'Ávila",
            'placa' => "ENS'0083",
            'renavam' => "803'456",
            'ativo' => 'S',
        ])->assertSee('transporte_veiculo_det.php?cod_veiculo=' . $vehicle . '"', false);
    }

    public function test_driver_name_filter_with_apostrophe_does_not_break_the_list(): void
    {
        $this->vehicleRecord();

        $this->listing(['nome_motorista' => "Zé D'Ávila ENS008"]);
    }

    private function listing(array $query): TestResponse
    {
        return $this->get(self::ROUTE . '?' . http_build_query($query))->assertSuccessful();
    }
}
