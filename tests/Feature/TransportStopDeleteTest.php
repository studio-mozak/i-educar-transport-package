<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesTransportUserRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportUserRecords.php';

class TransportStopDeleteTest extends TestCase
{
    use CreatesTransportUserRecords, DatabaseTransactions;

    public const API_CONTROLLER_FILE = __DIR__ . '/../../ieducar/modules/Api/Views/PontoController.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenControllerIsTaken('PontoController', \ApiCoreController::class);

        require_once self::API_CONTROLLER_FILE;
    }

    public function test_delete_removes_the_stop_and_clears_only_the_stop_of_its_transport_users(): void
    {
        $destination = $this->destinationRecord();
        $route = $this->routeRecord('Rota da Gameleira ENS011', 2026);
        $stop = $this->stopRecord('Ponto da Praça ENS011');
        $link = $this->transportUserRecord($this->personRecord(), $route, [
            'ref_cod_ponto_transporte_escolar' => $stop,
            'ref_idpes_destino' => $destination,
            'observacao' => 'Embarca com o irmão',
            'turno' => 2,
        ]);

        $this->deleteStopThroughApi($stop);

        $this->assertDatabaseMissing('modules.ponto_transporte_escolar', ['cod_ponto_transporte_escolar' => $stop]);

        $transportUser = $this->transportUser($link);

        $this->assertNull($transportUser->ref_cod_ponto_transporte_escolar);
        $this->assertSame($route, (int) $transportUser->ref_cod_rota_transporte_escolar);
        $this->assertSame($destination, (int) $transportUser->ref_idpes_destino);
        $this->assertSame('Embarca com o irmão', $transportUser->observacao);
        $this->assertSame(2, (int) $transportUser->turno);
        $this->assertSame(2026, (int) $transportUser->ano);
    }

    private function deleteStopThroughApi(int $stop): void
    {
        $controller = new \PontoController;
        $controller->setRequest(new \CoreExt_Controller_Request(['data' => ['id' => $stop]]));

        $response = (new \ReflectionMethod($controller, 'delete'))->invoke($controller);

        $this->assertSame(['id' => $stop], $response);
    }
}
