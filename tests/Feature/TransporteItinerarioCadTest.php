<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Services\RoutePathService;
use iEducar\Packages\Transport\Tests\CreatesDriverRecords;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use iEducar\Packages\Transport\View\RoutePathMap;
use iEducar\Packages\Transport\View\TransportMap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesDriverRecords.php';
require_once __DIR__ . '/../CreatesItineraryRecords.php';

class TransporteItinerarioCadTest extends TestCase
{
    use CreatesDriverRecords, CreatesItineraryRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    public const ROUTE = '/intranet/transporte_itinerario_cad.php';

    public const KEY = 'page-key-ENS009';

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
        $this->assertSame(1, preg_match('/<span role="group" aria-label="Dias de funcionamento".*?> Dom<\/label><\/span><\/span>/s', $response->getContent(), $weekdayGroup));
        $this->assertStringNotContainsString('nowrap', $weekdayGroup[0]);
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

    public function test_form_masks_each_stop_time_with_the_jquery_mask_plugin(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS016', 2026);
        $this->itineraryStopRecord($route, 1, $this->stopRecord('Ponto da Gameleira ENS016'), $this->vehicleRecord('Ônibus ENS016'), '06:30', 'V');

        $this->form($route)
            ->assertSee('name="hora[0]" id="hora[0]" value="06:30" size="6" maxlength="5"', false)
            ->assertSee("var stopTimeFieldSelector = 'input[name^=\"hora[\"]';", false)
            ->assertSee('var stopTimeMaskOptions = { translation: { H: { pattern: /[0-2]/ }, M: { pattern: /[0-5]/ } } };', false)
            ->assertSee("field.removeAttribute('onkeypress');", false)
            ->assertSee("field.setAttribute('inputmode', 'decimal');", false)
            ->assertSee("field.style.minWidth = 'calc(5ch + ' + horizontalBoxSpace(field) + 'px)';", false)
            ->assertSee("$j(field).removeAttr('maxlength').mask('H0:M0', stopTimeMaskOptions).off('input.stopTime').on('input.stopTime', reapplyStopTimeMask);", false)
            ->assertSeeInOrder([
                'var digitsBeforeCaret = digitCount(this.value.slice(0, this.selectionStart));',
                "$j(this).trigger('keyup.mask');",
                'if (isDeletion(event)) {',
                'var caret = caretAfterDigits(this.value, digitsBeforeCaret);',
                'this.setSelectionRange(caret, caret);',
            ], false)
            ->assertDontSee('clearIfNotMatch', false)
            ->assertSeeInOrder([
                'maskStopTimeFields();',
                '$addPontosButton.click(function(){',
                'setAutoComplete();',
                'maskStopTimeFields();',
            ], false);
    }

    public function test_form_shows_description_and_plate_of_a_saved_vehicle_without_driver(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS013', 2026);
        $vehicle = $this->vehicleRecord('Microonibus ENS013', ['placa' => 'TST1A23']);
        $this->itineraryStopRecord($route, 1, $this->stopRecord('Ponto da Gameleira ENS013'), $vehicle, '06:30', 'I');

        $this->form($route)
            ->assertSee('id="ref_cod_veiculo[0]" value="' . $vehicle . ' - Microonibus ENS013, Placa: TST1A23"', false);
    }

    public function test_form_shows_the_driver_of_a_saved_vehicle_that_has_one(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS013', 2026);
        $vehicle = $this->vehicleRecord('Microonibus ENS013', [
            'placa' => 'TST1A23',
            'ref_cod_motorista' => $this->driverRecord('Motorista ENS013', $this->companyRecord()),
        ]);
        $this->itineraryStopRecord($route, 1, $this->stopRecord('Ponto da Gameleira ENS013'), $vehicle, '06:30', 'I');

        $this->form($route)
            ->assertSee('id="ref_cod_veiculo[0]" value="' . $vehicle . ' - Microonibus ENS013, Placa: TST1A23, Motorista: Motorista ENS013"', false);
    }

    public function test_form_shows_the_route_path_block_below_the_stops_table(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        $this->itineraryStopRecord($route, 1, $this->stopRecord('Escola ENS009', ['latitude' => '-5.0912', 'longitude' => '-42.8011']), null, '06:30', 'I');

        $response = $this->form($route)
            ->assertSeeInOrder([
                'Itinerário', 'Veículo (Requer pré-cadastro)', 'ADICIONAR NOVO',
                '<tr id="tr_route_path"><td colspan="2" class="formlttd"><span class="form"><b>Trajeto</b></span>',
                '<div id="route_path_map" class="transport-map route-path-map" role="region" aria-label="Mapa do trajeto da rota"></div>',
                'route-path-outbound', ' Ida</span>', 'route-path-return', ' Volta</span>',
                '<p id="route_path_not_calculated" class="route-path-note">Trajeto ainda não calculado.</p>',
                '<button type="button" id="route_path_calculate" class="botaolistagem transport-button">Calcular trajeto</button>',
                'Km ida', 'name="km_ida" id="km_ida" value=""', 'km',
                'Km volta', 'name="km_volta" id="km_volta" value=""', 'km',
                'Percurso pavimentado', 'name="km_pav" id="km_pav" value=""', 'km',
                'Percurso não pavimentado', 'name="km_npav" id="km_npav" value=""', 'km',
            ], false)
            ->assertSee('<div id="route_path_stale" class="route-path-alert route-path-alert-notice" role="alert" hidden>' . RoutePathService::STALE_MESSAGE . '</div>', false)
            ->assertSee('<div id="route_path_failure" class="route-path-alert route-path-alert-error" role="alert" hidden>' . RoutePathService::UNAVAILABLE_MESSAGE . '</div>', false)
            ->assertSee('<p id="route_path_empty" class="route-path-note" hidden>', false)
            ->assertSee("name='trajeto_ida' id='trajeto_ida' type='hidden' value=''", false)
            ->assertSee("name='trajeto_volta' id='trajeto_volta' type='hidden' value=''", false)
            ->assertSee("name='trajeto_hash' id='trajeto_hash' type='hidden' value=''", false)
            ->assertSee('window.routePathMap = ', false)
            ->assertSee('.transport-map { isolation: isolate; width: 100%; border: 1px solid #cddce6; border-radius: 3px; }', false)
            ->assertSee('.route-path-map { height: 420px; }', false)
            ->assertSee('window.transportMap = {', false)
            ->assertDontSee('id="route_path_missing_key"', false);

        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertSame(1, preg_match('/<tr id="tr_route_path">.*?<\/td><\/tr>/s', $response->getContent(), $routePathRow));
        $this->assertStringNotContainsString('flashMessages__message', $routePathRow[0]);
    }

    public function test_form_without_key_hides_the_button_and_asks_to_configure_it(): void
    {
        config([RoutePathService::KEY_SETTING => '']);
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);

        $this->form($route)
            ->assertSee('<div id="route_path_missing_key" class="route-path-alert route-path-alert-neutral" role="alert">' . RoutePathService::MISSING_KEY_MESSAGE . '</div>', false)
            ->assertDontSee('id="route_path_calculate"', false)
            ->assertSee('id="km_pav"', false);
    }

    public function test_form_of_an_empty_itinerary_disables_the_button(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);
        $route = $this->routeRecord('Rota sem pontos ENS009', 2026);

        $this->form($route)
            ->assertSee('<p id="route_path_empty" class="route-path-note">' . RoutePathService::EMPTY_ITINERARY_MESSAGE . '</p>', false)
            ->assertSee('<button type="button" id="route_path_calculate" class="botaolistagem transport-button" disabled>Calcular trajeto</button>', false);
    }

    public function test_form_lists_the_stops_without_coordinates_with_a_link_to_each_one(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);
        $route = $this->routeRecord('Rota do Açude ENS009', 2026);
        $dam = $this->stopRecord('Açude ENS009');
        $this->itineraryStopRecord($route, 1, $dam, null, '06:30', 'I');
        $this->itineraryStopRecord($route, 2, $this->stopRecord('Escola ENS009', ['latitude' => '-5.0912', 'longitude' => '-42.8011']), null, '07:00', 'I');

        $this->form($route)
            ->assertSee('<div id="route_path_missing" class="route-path-alert route-path-alert-error" role="alert">Pontos sem localização: <span id="route_path_missing_list"><a href="/module/TransporteEscolar/Ponto?id=' . $dam . '">' . $dam . ' - Açude ENS009</a></span></div>', false)
            ->assertSee('<button type="button" id="route_path_calculate" class="botaolistagem transport-button" disabled>Calcular trajeto</button>', false);
    }

    public function test_form_loads_the_saved_path_and_distances(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);
        $route = $this->routeWithSavedPath();

        $content = $this->form($route)
            ->assertSee('<span id="route_path_calculated_at" class="route-path-note">Último cálculo: 12/11/2026 15:40</span>', false)
            ->assertSee('<p id="route_path_not_calculated" class="route-path-note" hidden>', false)
            ->assertSee('<div id="route_path_stale" class="route-path-alert route-path-alert-notice" role="alert" hidden>', false)
            ->assertSee('name="km_ida" id="km_ida" value="18,40"', false)
            ->assertSee('name="km_volta" id="km_volta" value="18,90"', false)
            ->assertSee('name="km_pav" id="km_pav" value="29,10"', false)
            ->assertSee('name="km_npav" id="km_npav" value="8,20"', false)
            ->getContent();

        $this->assertStringContainsString('"outbound":{"type":"LineString","coordinates":[[-42.8,-5.09],[-42.9,-5.2]]}', $content);
        $this->assertMatchesRegularExpression('/"reference":"\d+:I,\d+:V"/', $content);
    }

    public function test_form_shows_the_km_unit_next_to_each_of_the_four_distances(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);

        $this->form($this->routeWithSavedPath())
            ->assertSeeInOrder([
                'id="km_ida" value="18,40" size="10" maxlength="10" > km',
                'id="km_volta" value="18,90" size="10" maxlength="10" > km',
                'id="km_pav" value="29,10" size="10" maxlength="10" > km',
                'id="km_npav" value="8,20" size="10" maxlength="10" > km',
            ], false);
    }

    public function test_form_draws_each_direction_with_its_color_in_lines_legend_and_markers(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);

        $content = $this->form($this->routeWithSavedPath())->getContent();

        $this->assertStringContainsString('"colors":{"I":"#1f6fb2","V":"#b0561a"}', $content);
        $this->assertStringContainsString('.route-path-legend-line.route-path-outbound { background: #1f6fb2; }', $content);
        $this->assertStringContainsString('.route-path-legend-line.route-path-return { background: #b0561a; }', $content);
        $this->assertStringContainsString('.route-path-marker.route-path-return, .route-path-marker-segment.route-path-return { background: #b0561a; }', $content);
        $this->assertStringNotContainsString('#d9822b', $content);
    }

    public function test_form_keeps_the_map_under_the_fixed_action_bar_and_the_distances_on_a_narrow_screen(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);

        $this->form($this->routeWithSavedPath())
            ->assertSee('.transport-map { isolation: isolate;', false)
            ->assertSee('.route-path-map-wrapper { position: relative; isolation: isolate; }', false)
            ->assertSeeInOrder([
                '@media (max-width: 767px) {',
                'input#km_ida, input#km_volta, input#km_pav, input#km_npav { max-width: calc(100vw - 323px) !important; }',
            ], false);
    }

    public function test_form_warns_when_the_itinerary_changed_after_the_calculation(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);
        $route = $this->routeWithSavedPath();
        DB::table('modules.itinerario_transporte_escolar')->where('ref_cod_rota_transporte_escolar', $route)->where('seq', 2)->update(['tipo' => 'I']);

        $this->form($route)
            ->assertSee('<div id="route_path_stale" class="route-path-alert route-path-alert-notice" role="alert">' . RoutePathService::STALE_MESSAGE . '</div>', false)
            ->assertSee('"reference":null', false);
    }

    public function test_calculate_button_keeps_the_look_of_the_button_while_busy_or_disabled(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);

        $content = $this->form($this->routeWithSavedPath())
            ->assertSee('.transport-map-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 8px; }', false)
            ->assertSee('.botaolistagem.transport-button { width: auto; min-width: 160px; white-space: nowrap; }', false)
            ->assertSeeInOrder([
                '.botaolistagem.transport-button:disabled, .botaolistagem.transport-button[aria-disabled="true"] { background-color: #ccdce6; color: #47728f; border: 1px solid #cddce6; opacity: .6; cursor: not-allowed; }',
                '.botaolistagem.transport-button[aria-busy="true"] { cursor: progress; }',
            ], false)
            ->assertSee('<div class="transport-map-actions"><button type="button" id="route_path_calculate" class="botaolistagem transport-button">Calcular trajeto</button>', false)
            ->assertSee('transportMap.setButtonBusy(button(), true, config.messages.calculating);', false)
            ->getContent();

        $this->assertSame(1, preg_match('/<tr id="tr_route_path">.*?<\/td><\/tr>/s', $content, $routePathRow));
        $this->assertStringNotContainsString('botaolistagemdisabled', $routePathRow[0]);
        $this->assertStringNotContainsString('route-path-actions', $content);
        $this->assertStringNotContainsString('botaolistagemdisabled', file_get_contents(RoutePathMap::SCRIPT));
        $this->assertStringNotContainsString('botaolistagemdisabled', file_get_contents(TransportMap::SCRIPT));
    }

    public function test_invalid_times_reject_the_form_citing_each_line_and_keep_what_was_typed(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS016', 2026, ['abrangencia' => 'M', 'dias_semana' => '2,4']);
        [$farm, $school, $square] = $this->threeStops();
        $this->itineraryStopRecord($route, 1, $farm, null, '06:30', 'I');
        $this->itineraryStopRecord($route, 2, $school, null, '07:00', 'I');
        $itineraryBefore = $this->itinerary($route);

        $response = $this->saveItinerary($route, [
            'descricao' => 'Rota+da+Gameleira+ENS016',
            'abrangencia' => 'I',
            'dias_semana' => ['6'],
            'ref_cod_ponto_transporte_escolar' => [
                $farm . ' - Fazenda ENS016',
                $school . ' - Escola ENS016',
                $square . ' - Praça ENS016',
            ],
            'hora' => ['06:40', '25:70', '6h'],
            'tipo' => ['I', 'I', 'V'],
            'ref_cod_veiculo' => ['', '', ''],
            'km_ida' => '12,50',
        ])->assertSuccessful();

        $this->assertSame(
            'Linha 2: Informe a hora no formato HH:MM, ex.: 06:30.<br>Linha 3: Informe a hora no formato HH:MM, ex.: 06:30.<br>',
            session('error')
        );

        $saved = $this->route($route);
        $this->assertSame('M', $saved->abrangencia);
        $this->assertSame('2,4', $saved->dias_semana);
        $this->assertNull($saved->km_ida);
        $this->assertSame($itineraryBefore, $this->itinerary($route));

        $response->assertSee('id="ref_cod_ponto_transporte_escolar[0]" value="' . $farm . ' - Fazenda ENS016"', false)
            ->assertSee('id="ref_cod_ponto_transporte_escolar[2]" value="' . $square . ' - Praça ENS016"', false)
            ->assertSee('id="hora[0]" value="06:40"', false)
            ->assertSee('id="hora[1]" value="25:70"', false)
            ->assertSee('id="hora[2]" value="6h"', false)
            ->assertSee('id="km_ida" value="12,50"', false)
            ->assertSee('Rota da Gameleira ENS016', false)
            ->assertSee("id='btn_cancelar'", false)
            ->assertDontSee('Rota%2Bda', false);

        $content = $response->getContent();
        $this->assertMatchesRegularExpression('/<option[^>]*value=["\']I["\'][^>]*selected/', $content);
        $this->assertStringContainsString('id="dias_semana_6" value="6" checked>', $content);
        $this->assertStringContainsString('id="dias_semana_2" value="2">', $content);
    }

    public function test_typed_values_shown_after_a_rejection_are_escaped(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS016', 2026);

        $response = $this->saveItinerary($route, [
            'ref_cod_ponto_transporte_escolar' => ['1 - <script>alert(1)</script>'],
            'hora' => ['"><u>'],
            'tipo' => ['I'],
            'ref_cod_veiculo' => ['2 - "><i>ENS016</i>'],
        ])->assertSuccessful();

        $this->assertSame('Linha 1: Informe a hora no formato HH:MM, ex.: 06:30.<br>', session('error'));
        $response->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('"><u>', false)
            ->assertDontSee('<i>ENS016</i>', false)
            ->assertSee('value="1 - &lt;script&gt;alert(1)&lt;/script&gt;"', false)
            ->assertSee('id="hora[0]" value="&quot"', false)
            ->assertSee('value="2 - &quot;&gt;&lt;i&gt;ENS016&lt;/i&gt;"', false);
    }

    public function test_empty_times_and_times_with_seconds_are_saved(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS016', 2026);
        [$farm, $school, $square] = $this->threeStops();

        $this->saveItinerary($route, [
            'ref_cod_ponto_transporte_escolar' => [
                $farm . ' - Fazenda ENS016',
                $school . ' - Escola ENS016',
                $square . ' - Praça ENS016',
            ],
            'hora' => ['', '23:59', '06:30:00'],
            'tipo' => ['I', 'I', 'V'],
            'ref_cod_veiculo' => ['', '', ''],
        ])->assertRedirect('transporte_rota_det.php?cod_rota=' . $route);

        $this->assertSame([null, '23:59:00', '06:30:00'], array_column($this->itinerary($route), 'hora'));
    }

    public static function failuresInTheMiddleOfTheSave(): array
    {
        return [
            'stop without code' => ['Ponto sem código ENS016', ''],
            'vehicle that does not exist' => [null, '2147483000 - Ônibus inexistente ENS016'],
        ];
    }

    #[DataProvider('failuresInTheMiddleOfTheSave')]
    public function test_a_failure_in_the_middle_of_the_save_keeps_the_saved_route_and_itinerary(?string $secondStop, string $secondVehicle): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS016', 2026, ['abrangencia' => 'M', 'dias_semana' => '2,4', 'km_ida' => 18.4]);
        [$farm, $school] = $this->threeStops();
        $this->itineraryStopRecord($route, 1, $farm, null, '06:30', 'I');
        $this->itineraryStopRecord($route, 2, $school, null, '07:00', 'I');
        $routeBefore = $this->route($route);
        $itineraryBefore = $this->itinerary($route);

        $response = $this->saveItinerary($route, [
            'abrangencia' => 'I',
            'dias_semana' => ['6'],
            'km_ida' => '20,00',
            'ref_cod_ponto_transporte_escolar' => [$school . ' - Escola ENS016', $secondStop ?? $farm . ' - Fazenda ENS016'],
            'hora' => ['06:40', '07:10'],
            'tipo' => ['I', 'V'],
            'ref_cod_veiculo' => ['', $secondVehicle],
        ])->assertSuccessful();

        $this->assertSame('Cadastro não realizado.<br>', session('error'));
        $this->assertEquals($routeBefore, $this->route($route));
        $this->assertSame($itineraryBefore, $this->itinerary($route));
        $response->assertSee('id="ref_cod_ponto_transporte_escolar[0]" value="' . $school . ' - Escola ENS016"', false)
            ->assertSee('id="hora[1]" value="07:10"', false)
            ->assertSee('id="km_ida" value="20,00"', false);
    }

    private function threeStops(): array
    {
        return [
            $this->stopRecord('Fazenda ENS016'),
            $this->stopRecord('Escola ENS016'),
            $this->stopRecord('Praça ENS016'),
        ];
    }

    private function routeWithSavedPath(): int
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026, ['km_pav' => 29.1, 'km_npav' => 8.2]);
        $school = $this->stopRecord('Escola ENS009', ['latitude' => '-5.0912', 'longitude' => '-42.8011']);
        $farm = $this->stopRecord('Fazenda ENS009', ['latitude' => '-5.2001', 'longitude' => '-42.9001']);
        $this->itineraryStopRecord($route, 1, $farm, null, '06:30', 'I');
        $this->itineraryStopRecord($route, 2, $school, null, '11:30', 'V');

        DB::table('modules.rota_transporte_escolar')->where('cod_rota_transporte_escolar', $route)->update([
            'km_ida' => 18.4,
            'km_volta' => 18.9,
            'trajeto_ida' => '{"type":"LineString","coordinates":[[-42.8,-5.09],[-42.9,-5.2]]}',
            'trajeto_volta' => '{"type":"LineString","coordinates":[[-42.9,-5.2],[-42.8,-5.09]]}',
            'trajeto_hash' => RoutePathService::hash((new RoutePathService)->savedStops($route)),
            'trajeto_calculado_em' => '2026-11-12 15:40:00',
        ]);

        return $route;
    }

    private function form(int $route): TestResponse
    {
        $_GET = ['cod_rota' => $route];

        return $this->get(self::ROUTE . '?cod_rota=' . $route)->assertSuccessful();
    }
}
