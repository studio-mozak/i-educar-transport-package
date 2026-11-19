<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Api\DiarioApiRequestTestTrait;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class RouteCoverageWeekdaysTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions, DiarioApiRequestTestTrait;

    public const ROUTE_API = '/module/Api/Rota';

    public const STOPS_KEPT_MESSAGE = 'Abrangência e dias salvos. Os pontos do itinerário não foram removidos; para apagar todos, use Excluir todos.<br>';

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
    }

    public function test_itinerary_saves_coverage_and_weekdays_and_keeps_the_stops(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026);
        $firstStop = $this->stopRecord('Ponto da Gameleira ENS010');
        $secondStop = $this->stopRecord('Escola Municipal ENS010');
        $vehicle = $this->vehicleRecord('Ônibus da Gameleira ENS010');
        $this->itineraryStopRecord($route, 1, $firstStop, $vehicle, '06:30', 'I');
        $this->itineraryStopRecord($route, 2, $secondStop, $vehicle, '11:45', 'V');
        $itineraryBefore = $this->itinerary($route);

        $this->saveItinerary($route, [
            'abrangencia' => 'I',
            'dias_semana' => ['6', '2', '4'],
            'ref_cod_ponto_transporte_escolar' => [
                $firstStop . ' - Ponto da Gameleira ENS010',
                $secondStop . ' - Escola Municipal ENS010',
            ],
            'hora' => ['06:30', '11:45'],
            'tipo' => ['I', 'V'],
            'ref_cod_veiculo' => [
                $vehicle . ' - Ônibus da Gameleira ENS010',
                $vehicle . ' - Ônibus da Gameleira ENS010',
            ],
        ])->assertRedirect('transporte_rota_det.php?cod_rota=' . $route);

        $saved = $this->route($route);
        $this->assertSame('I', $saved->abrangencia);
        $this->assertSame('2,4,6', $saved->dias_semana);
        $this->assertSame($itineraryBefore, $this->itinerary($route));
    }

    public function test_itinerary_saves_coverage_and_weekdays_without_stops_and_keeps_the_saved_stops(): void
    {
        $route = $this->routeRecord('Rota do Açude ENS010', 2026);
        $this->itineraryStopRecord($route, 1, $this->stopRecord('Ponto do Açude ENS010'), null, '07:00', 'I');
        $itineraryBefore = $this->itinerary($route);

        $this->saveItinerary($route, [
            'abrangencia' => 'M',
            'dias_semana' => ['2', '3', '4', '5', '6'],
        ])->assertRedirect('transporte_rota_det.php?cod_rota=' . $route);

        $saved = $this->route($route);
        $this->assertSame('M', $saved->abrangencia);
        $this->assertSame('2,3,4,5,6', $saved->dias_semana);
        $this->assertSame($itineraryBefore, $this->itinerary($route));
        $this->assertSame(self::STOPS_KEPT_MESSAGE, session('legacy'));
    }

    public function test_itinerary_without_saved_stops_saves_without_the_kept_stops_message(): void
    {
        $route = $this->routeRecord('Rota sem pontos ENS010', 2026);

        $this->saveItinerary($route, [
            'abrangencia' => 'M',
            'dias_semana' => ['3', '5'],
        ])->assertRedirect('transporte_rota_det.php?cod_rota=' . $route);

        $saved = $this->route($route);
        $this->assertSame('M', $saved->abrangencia);
        $this->assertSame('3,5', $saved->dias_semana);
        $this->assertSame([], $this->itinerary($route));
        $this->assertNotSame(self::STOPS_KEPT_MESSAGE, session('legacy'));
    }

    public function test_itinerary_saves_a_route_without_coverage_and_weekdays(): void
    {
        $route = $this->routeRecord('Rota antiga ENS010', 2026);

        $this->saveItinerary($route, ['abrangencia' => ''])
            ->assertRedirect('transporte_rota_det.php?cod_rota=' . $route);

        $saved = $this->route($route);
        $this->assertNull($saved->abrangencia);
        $this->assertNull($saved->dias_semana);
    }

    public function test_itinerary_clears_coverage_and_weekdays(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026, ['abrangencia' => 'I', 'dias_semana' => '2,4,6']);

        $this->saveItinerary($route, ['abrangencia' => ''])
            ->assertRedirect('transporte_rota_det.php?cod_rota=' . $route);

        $saved = $this->route($route);
        $this->assertNull($saved->abrangencia);
        $this->assertNull($saved->dias_semana);
    }

    public function test_itinerary_stores_sunday_as_1_and_saturday_as_7_and_the_detail_lists_them_in_screen_order(): void
    {
        $route = $this->routeRecord('Rota de fim de semana ENS010', 2026);

        $this->saveItinerary($route, [
            'abrangencia' => 'M',
            'dias_semana' => ['1', '7'],
        ])->assertRedirect('transporte_rota_det.php?cod_rota=' . $route);

        $stored = $this->route($route)->dias_semana;

        $this->assertSame('1,7', $stored);
        $this->assertSame('Sáb, Dom', \clsModulesRotaTransporteEscolar::weekdaysNames($stored));
    }

    public static function invalidInputs(): array
    {
        return [
            'coverage X' => [['abrangencia' => 'X', 'dias_semana' => ['1']]],
            'weekday 9' => [['abrangencia' => 'I', 'dias_semana' => ['1', '9']]],
            'weekday 0' => [['abrangencia' => 'I', 'dias_semana' => ['0']]],
            'weekday with sql' => [['abrangencia' => 'I', 'dias_semana' => ['1; DROP TABLE x']]],
            'weekdays as text' => [['abrangencia' => 'I', 'dias_semana' => '1,2']],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_itinerary_rejects_invalid_coverage_or_weekdays(array $input): void
    {
        $this->assertInvalidInputIsRejected($input);
    }

    public function test_route_form_keeps_coverage_and_weekdays(): void
    {
        $this->skipWhenControllerIsTaken('RotaController', \ApiCoreController::class);

        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026, ['abrangencia' => 'I', 'dias_semana' => '2,4,6']);
        $saved = $this->route($route);

        $this->postResource(self::ROUTE_API, [
            'oper' => 'put',
            'resource' => 'rota',
            'id' => $route,
            'desc' => 'Rota da Gameleira editada ENS010',
            'pessoaj_id' => $saved->ref_idpes_destino,
            'ano' => $saved->ano,
            'tipo_rota' => 'R',
            'empresa_id' => $this->companyRecord(),
        ])->assertSuccessful()->assertJsonPath('any_error_msg', false);

        $edited = $this->route($route);
        $this->assertSame('Rota da Gameleira editada ENS010', $edited->descricao);
        $this->assertSame('R', $edited->tipo_rota);
        $this->assertSame('I', $edited->abrangencia);
        $this->assertSame('2,4,6', $edited->dias_semana);
    }

    public function test_route_copy_copies_coverage_and_weekdays(): void
    {
        $company = $this->companyRecord();
        $filled = $this->routeRecord('Rota da Gameleira ENS010', 2026, [
            'ref_cod_empresa_transporte_escolar' => $company,
            'abrangencia' => 'I',
            'dias_semana' => '2,4,6',
        ]);
        $old = $this->routeRecord('Rota antiga ENS010', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        $this->itineraryStopRecord($filled, 1, $this->stopRecord('Ponto da Gameleira ENS010'), null, '06:30', 'I');
        $this->itineraryStopRecord($old, 1, $this->stopRecord('Ponto antigo ENS010'), null, '07:00', 'I');

        $this->copyRoutes($company)->assertSuccessful();

        $copies = DB::table('modules.rota_transporte_escolar')
            ->where('ref_cod_empresa_transporte_escolar', $company)
            ->where('ano', 2027)
            ->get()
            ->keyBy('descricao');

        $this->assertCount(2, $copies);
        $this->assertSame('I', $copies['Rota da Gameleira ENS010']->abrangencia);
        $this->assertSame('2,4,6', $copies['Rota da Gameleira ENS010']->dias_semana);
        $this->assertNull($copies['Rota antiga ENS010']->abrangencia);
        $this->assertNull($copies['Rota antiga ENS010']->dias_semana);
    }

    private function assertInvalidInputIsRejected(array $input): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS010', 2026, ['abrangencia' => 'M', 'dias_semana' => '2,4']);
        $stop = $this->stopRecord('Ponto da Gameleira ENS010');
        $this->itineraryStopRecord($route, 1, $stop, null, '06:30', 'I');
        $itineraryBefore = $this->itinerary($route);

        $response = $this->saveItinerary($route, array_merge($input, [
            'descricao' => 'Rota+da+Gameleira+ENS010',
            'ref_cod_ponto_transporte_escolar' => [$this->stopRecord('Ponto novo ENS010') . ' - Ponto novo ENS010'],
            'hora' => ['09:00'],
            'tipo' => ['V'],
            'ref_cod_veiculo' => [''],
        ]))->assertSuccessful();

        $saved = $this->route($route);
        $this->assertSame('M', $saved->abrangencia);
        $this->assertSame('2,4', $saved->dias_semana);
        $this->assertSame($itineraryBefore, $this->itinerary($route));
        $this->assertSame('Abrangência ou dias de funcionamento inválidos.<br>', session('error'));

        $this->assertFormShowsTheSavedRoute($response, $stop);
    }

    private function assertFormShowsTheSavedRoute(TestResponse $response, int $savedStop): void
    {
        $content = $response->getContent();

        $this->assertSame(1, substr_count($content, 'Rota+da+Gameleira'));

        $response->assertSee('Rota da Gameleira ENS010', false)
            ->assertSee("name='descricao' id='descricao' type='hidden' value='Rota+da+Gameleira+ENS010'", false)
            ->assertDontSee('Rota%2Bda', false)
            ->assertSee('id="ref_cod_ponto_transporte_escolar[0]" value="' . $savedStop . ' - Ponto da Gameleira ENS010"', false)
            ->assertDontSee('Ponto novo ENS010', false)
            ->assertSee('06:30', false)
            ->assertSee("id='btn_cancelar'", false)
            ->assertSee('transporte_rota_det.php?cod_rota=', false);

        $this->assertMatchesRegularExpression('/<option[^>]*value=["\']M["\'][^>]*selected/', $content);
        $this->assertDoesNotMatchRegularExpression('/<option[^>]*value=["\']X["\']/', $content);

        foreach ([2, 4] as $day) {
            $this->assertStringContainsString('id="dias_semana_' . $day . '" value="' . $day . '" checked>', $content);
        }

        foreach ([3, 5, 6, 7, 1] as $day) {
            $this->assertStringContainsString('id="dias_semana_' . $day . '" value="' . $day . '">', $content);
        }
    }
}
