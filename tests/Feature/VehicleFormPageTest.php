<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesTransportRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportRecords.php';

class VehicleFormPageTest extends TestCase
{
    use CreatesTransportRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    public const ROUTE = '/module/TransporteEscolar/Veiculo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenVehicleControllerIsTaken(\Portabilis_Controller_Page_EditController::class);
        $_GET = [];
    }

    public function test_form_shows_model_after_brand_and_outsourced_before_active(): void
    {
        $this->get(self::ROUTE)
            ->assertSuccessful()
            ->assertSee('Ensinus - Veículos', false)
            ->assertDontSee('Ensinus - Motoristas', false)
            ->assertDontSee('Abrangência', false)
            ->assertSeeInOrder(['id="marca"', "for='modelo'>Modelo</label>", 'id="ano_fabricacao"'], false)
            ->assertSee('type=\'text\' name="modelo" id="modelo" value="" size="50" maxlength="50"', false)
            ->assertSeeInOrder([
                'id="adaptado_necessidades_especiais"',
                "for='terceirizado'>Terceirizado</label>",
                'type=\'checkbox\' name="terceirizado" id="terceirizado"',
                'id="ativo"',
            ], false);
    }
}
