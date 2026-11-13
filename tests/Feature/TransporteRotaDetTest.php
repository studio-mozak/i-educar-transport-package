<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Testing\TestResponse;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class TransporteRotaDetTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    public const ROUTE = '/intranet/transporte_rota_det.php';

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
    }

    public function test_detail_shows_coverage_and_weekdays_after_the_route_type(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026, ['abrangencia' => 'I', 'dias_semana' => '2,4,6']);

        $this->detail($route)
            ->assertSeeInOrder([
                'Tipo da rota', 'Urbana',
                'Abrangência', 'Intermunicipal',
                'Dias de funcionamento', 'Seg, Qua, Sex',
                'Terceirizado', 'Itinerário',
            ], false)
            ->assertDontSee('Não informado');
    }

    public function test_detail_lists_every_weekday_from_monday_to_sunday(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026, ['abrangencia' => 'M', 'dias_semana' => '7,6,5,4,3,2,1']);

        $this->detail($route)
            ->assertSeeInOrder(['Abrangência', 'Municipal', 'Dias de funcionamento', 'Seg, Ter, Qua, Qui, Sex, Sáb, Dom'], false);
    }

    public function test_detail_lists_sunday_1_after_saturday_7(): void
    {
        $route = $this->routeRecord('Rota de fim de semana ENS010', 2026, ['abrangencia' => 'M', 'dias_semana' => '1,7']);

        $this->detail($route)
            ->assertSeeInOrder(['Dias de funcionamento', 'Sáb, Dom', 'Terceirizado'], false)
            ->assertDontSee('Dom, Sáb', false);
    }

    public function test_detail_shows_not_informed_for_an_old_route(): void
    {
        $route = $this->routeRecord('Rota antiga ENS010', 2026);

        $this->detail($route)
            ->assertSeeInOrder([
                'Tipo da rota', 'Urbana',
                'Abrangência', 'Não informado',
                'Dias de funcionamento', 'Não informado',
                'Terceirizado',
            ], false);
    }

    public function test_detail_keeps_the_itinerary_table(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026, ['abrangencia' => 'I', 'dias_semana' => '2']);
        $this->itineraryStopRecord($route, 1, $this->stopRecord('Ponto da Gameleira ENS010'), $this->vehicleRecord('Ônibus ENS010'), '06:30', 'I');
        $this->itineraryStopRecord($route, 2, $this->stopRecord('Escola Municipal ENS010'), null, '11:45', 'V');

        $this->detail($route)
            ->assertSeeInOrder(['Dias de funcionamento', 'Seg', 'Itinerário', 'Editar itinerário', 'Sequencial', 'Ponto', 'Hora', 'Tipo', 'Veículo'], false)
            ->assertSeeInOrder(['Ponto da Gameleira ENS010', '06:30', 'Ida', 'Ônibus ENS010', 'Escola Municipal ENS010', '11:45', 'Volta'], false);
    }

    private function detail(int $route): TestResponse
    {
        $_GET = ['cod_rota' => $route];

        return $this->get(self::ROUTE . '?cod_rota=' . $route)->assertSuccessful();
    }
}
