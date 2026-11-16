<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Services\RoutePathService;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class TransporteRotaDetTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    public const ROUTE = '/intranet/transporte_rota_det.php';

    public const PATH_ROW_START = '<div class="formlttd route-path-detail-row"><div class="route-path-detail-label">Trajeto:</div><div class="route-path route-path-detail">';

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

    public function test_detail_shows_the_saved_path_read_only_after_the_itinerary(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        $farm = $this->stopRecord('Fazenda ENS009', ['latitude' => '-5.2001', 'longitude' => '-42.9001']);
        $this->itineraryStopRecord($route, 1, $farm, null, '06:30', 'I');
        $this->savePath($route, 18.4, 18.9);

        $this->detail($route)
            ->assertSeeInOrder([
                'Itinerário', 'Editar itinerário',
                "<tr><td colspan='2'>" . self::PATH_ROW_START,
                '<div id="route_path_map" class="transport-map route-path-map" role="region" aria-label="Mapa do trajeto da rota"></div>',
                ' Ida</span>', ' Volta</span>',
                'Km ida / Km volta:', '18,4 km / 18,9 km',
            ], false)
            ->assertSee('<p id="route_path_not_calculated" class="route-path-note" hidden>', false)
            ->assertSee('<div id="route_path_stale" class="route-path-alert route-path-alert-notice" role="alert" hidden>', false)
            ->assertSee('"editable":false', false)
            ->assertSee('.route-path-detail .route-path-map { height: 320px; }', false)
            ->assertDontSee('id="route_path_calculate"', false);
    }

    public function test_detail_shows_paved_and_unpaved_distances_with_a_decimal_comma(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        DB::table('modules.rota_transporte_escolar')->where('cod_rota_transporte_escolar', $route)->update(['km_pav' => 4.14, 'km_npav' => 150]);

        $this->detail($route)
            ->assertSeeInOrder(['Percurso pavimentado:', '4,14 km', 'Percurso não pavimentado:', '150 km'], false)
            ->assertDontSee('4.14 km', false);
    }

    public function test_detail_without_a_saved_path_says_it_was_not_calculated(): void
    {
        $route = $this->routeRecord('Rota antiga ENS009', 2026);

        $this->detail($route)
            ->assertSeeInOrder([self::PATH_ROW_START, '<p id="route_path_not_calculated" class="route-path-note">Trajeto ainda não calculado.</p>'], false)
            ->assertDontSee('Km ida / Km volta', false)
            ->assertDontSee('<span class="form"><b>Trajeto</b></span>', false);
    }

    public function test_detail_path_title_uses_the_detail_label_style(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);

        $response = $this->detail($route);

        $this->assertSame(1, preg_match("/<tr><td class='(formmdtd|formlttd)' width='20%'>Itinerário:<\/td>/", $response->getContent()));
        $response->assertSee(self::PATH_ROW_START, false)
            ->assertSee('.route-path-detail-label { margin-bottom: 8px; }', false);
    }

    public function test_detail_groups_stops_at_the_same_place_into_one_marker(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        $farm = $this->stopRecord('Fazenda ENS009', ['latitude' => '-5.2001', 'longitude' => '-42.9001']);
        $school = $this->stopRecord('Escola ENS009', ['latitude' => '-5.0912', 'longitude' => '-42.8011']);
        $this->itineraryStopRecord($route, 1, $farm, null, '06:30', 'I');
        $this->itineraryStopRecord($route, 2, $school, null, '07:00', 'I');
        $this->itineraryStopRecord($route, 3, $school, null, '11:30', 'V');
        $this->itineraryStopRecord($route, 4, $farm, null, '12:00', 'V');

        $this->detail($route)
            ->assertSee('"directions":{"I":"Ida","V":"Volta"}', false)
            ->assertSee('"stop":' . $school . ',"name":"Escola ENS009","direction":"V","latitude":"-5.0912","longitude":"-42.8011","number":3', false)
            ->assertSee('.route-path-marker-segment + .route-path-marker-segment { border-left: 1px solid #fff; }', false);
    }

    public function test_detail_warns_when_the_itinerary_changed_after_the_calculation(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        $farm = $this->stopRecord('Fazenda ENS009', ['latitude' => '-5.2001', 'longitude' => '-42.9001']);
        $this->itineraryStopRecord($route, 1, $farm, null, '06:30', 'I');
        $this->savePath($route, 18.4, null);
        $this->itineraryStopRecord($route, 2, $this->stopRecord('Escola ENS009'), null, '07:00', 'I');

        $this->detail($route)
            ->assertSee('<div id="route_path_stale" class="route-path-alert route-path-alert-notice" role="alert">' . RoutePathService::STALE_MESSAGE . '</div>', false)
            ->assertSeeInOrder(['Km ida / Km volta:', '18,4 km / -'], false);
    }

    private function savePath(int $route, ?float $outbound, ?float $return): void
    {
        DB::table('modules.rota_transporte_escolar')->where('cod_rota_transporte_escolar', $route)->update([
            'km_ida' => $outbound,
            'km_volta' => $return,
            'trajeto_ida' => '{"type":"LineString","coordinates":[[-42.9,-5.2],[-42.8,-5.09]]}',
            'trajeto_hash' => RoutePathService::hash((new RoutePathService)->savedStops($route)),
            'trajeto_calculado_em' => '2026-11-12 15:40:00',
        ]);
    }

    private function detail(int $route): TestResponse
    {
        $_GET = ['cod_rota' => $route];

        return $this->get(self::ROUTE . '?cod_rota=' . $route)->assertSuccessful();
    }
}
