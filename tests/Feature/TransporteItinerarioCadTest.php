<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Testing\TestResponse;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class TransporteItinerarioCadTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    public const ROUTE = '/intranet/transporte_itinerario_cad.php';

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
    }

    public function test_form_shows_coverage_and_weekdays_above_the_stops_table(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026);

        $this->form($route)
            ->assertSeeInOrder([
                'Código da rota', 'Rota', 'Rota da Gameleira ENS010',
                'Abrangência', 'Selecione', 'Municipal', 'Intermunicipal',
                'Dias de funcionamento', 'role="group" aria-label="Dias de funcionamento"',
                '> Seg<', '> Ter<', '> Qua<', '> Qui<', '> Sex<', '> Sáb<', '> Dom<',
                'Itinerário', 'Ponto (Requer pré-cadastro)', 'Hora', 'Tipo', 'Veículo (Requer pré-cadastro)',
            ], false);
    }

    public function test_form_renders_each_weekday_with_its_own_clickable_label(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026);

        $response = $this->form($route);

        foreach ([2 => 'Seg', 3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb', 1 => 'Dom'] as $day => $name) {
            $response->assertSee(
                '<label for="dias_semana_' . $day . '" style="margin: 0 12px 0 0; font-weight: normal; cursor: pointer;"><input type="checkbox" name="dias_semana[]" id="dias_semana_' . $day . '" value="' . $day . '"> ' . $name . '</label>',
                false
            );
        }

        $response->assertSeeInOrder(array_map(fn (int $day) => 'id="dias_semana_' . $day . '" value="' . $day . '"', [2, 3, 4, 5, 6, 7, 1]), false);
        $wrappingRow = 'display: flex; flex-wrap: wrap; row-gap: 6px;';

        $response->assertSeeInOrder([
            '<span role="group" aria-label="Dias de funcionamento" style="' . $wrappingRow . ' max-width: calc(100vw - 280px);">',
            '<span style="' . $wrappingRow . '">', '> Seg<', '> Qui<', '</span>',
            '<span style="' . $wrappingRow . '">', '> Sex<', '> Dom<', '</span>',
        ], false);
        $response->assertDontSee('white-space: nowrap;', false);
    }

    public function test_form_loads_the_saved_coverage_and_weekdays(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026, ['abrangencia' => 'I', 'dias_semana' => '2,4,6']);

        $content = $this->form($route)->getContent();

        $this->assertMatchesRegularExpression('/<option[^>]*value=["\']I["\'][^>]*selected/', $content);
        $this->assertDoesNotMatchRegularExpression('/<option[^>]*value=["\']M["\'][^>]*selected/', $content);

        foreach ([2, 4, 6] as $day) {
            $this->assertStringContainsString('id="dias_semana_' . $day . '" value="' . $day . '" checked>', $content);
        }

        foreach ([3, 5, 7, 1] as $day) {
            $this->assertStringContainsString('id="dias_semana_' . $day . '" value="' . $day . '">', $content);
        }
    }

    public function test_form_of_an_old_route_has_no_coverage_and_no_weekday_checked(): void
    {
        $route = $this->routeRecord('Rota antiga ENS010', 2026);

        $content = $this->form($route)->getContent();

        $this->assertDoesNotMatchRegularExpression('/<option[^>]*value=["\'][MI]["\'][^>]*selected/', $content);
        $this->assertStringNotContainsString('" checked>', $content);
    }

    public function test_form_keeps_loading_the_saved_stops(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026);
        $stop = $this->stopRecord('Ponto da Gameleira ENS010');
        $vehicle = $this->vehicleRecord('Ônibus ENS010');
        $this->itineraryStopRecord($route, 1, $stop, $vehicle, '06:30', 'V');

        $this->form($route)
            ->assertSee($stop . ' - Ponto da Gameleira ENS010', false)
            ->assertSee('id="ref_cod_veiculo[0]" value="' . $vehicle . ' - ', false)
            ->assertSee('06:30', false);
    }

    private function form(int $route): TestResponse
    {
        $_GET = ['cod_rota' => $route];

        return $this->get(self::ROUTE . '?cod_rota=' . $route)->assertSuccessful();
    }
}
