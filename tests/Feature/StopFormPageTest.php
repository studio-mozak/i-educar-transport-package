<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Services\RoutePathService;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use iEducar\Packages\Transport\View\StopLocationMap;
use iEducar\Packages\Transport\View\TransportMap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class StopFormPageTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    public const PAGE = '/module/TransporteEscolar/Ponto';

    public const KEY = 'page-key-ENS014';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenControllerIsTaken('PontoController', \Portabilis_Controller_Page_EditController::class);

        $_GET = [];
    }

    public function test_page_does_not_load_google_maps_nor_the_core_stop_script(): void
    {
        $content = $this->page()->getContent();

        $this->assertStringNotContainsString('gmaps.js', $content);
        $this->assertStringNotContainsString('singleton_gmap', $content);
        $this->assertStringNotContainsString('TransporteEscolar/Assets/Javascripts/Ponto.js', $content);
        $this->assertStringNotContainsString("attr('readonly'", $content);
        $this->assertStringNotContainsString('#DFDFDF', $content);
    }

    public function test_page_keeps_the_coordinates_only_as_hidden_fields_and_shows_the_map_after_the_city(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);

        $response = $this->page()
            ->assertSeeInOrder([
                'Município',
                '<tr id="tr_stop_location"><td colspan="2" class="formlttd"><div class="stop-location"><span class="form"><b>Localização no mapa</b></span>',
                '<div class="stop-location-help"><sub class="modal-sub">Clique no mapa ou arraste o marcador para marcar o ponto.</sub></div>',
                '<div id="stop_location_map" class="transport-map stop-location-map" role="region" aria-label="Mapa para marcar a localização do ponto"></div>',
                '<div id="stop_location_notice" role="status"></div>',
                '<div id="stop_location_status" class="sr-only" aria-live="polite"></div>',
            ], false)
            ->assertSee("name='latitude' id='latitude' type='hidden' value=''", false)
            ->assertSee("name='longitude' id='longitude' type='hidden' value=''", false)
            ->assertSee('window.stopLocationMap = ', false)
            ->assertSee('window.transportMap = {', false)
            ->assertSee('.stop-location-map { height: 360px; cursor: crosshair; }', false)
            ->assertSee('.stop-location { margin: 8px 0 12px; contain: inline-size; }', false)
            ->assertDontSee('Se preferir, digite a latitude e a longitude acima.', false)
            ->assertDontSee('De -90 a 90', false)
            ->assertDontSee('De -180 a 180', false)
            ->assertDontSee('Ex.: -5.091234', false)
            ->assertDontSee('Ex.: -42.837512', false)
            ->assertDontSee('transport-coordinate-error', false);

        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="(latitude|longitude)"/', $response->getContent());
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
    }

    public function test_page_with_key_brings_the_locate_button_the_remove_link_and_the_hint(): void
    {
        config([RoutePathService::KEY_SETTING => self::KEY]);

        $response = $this->page()
            ->assertSee(
                '<div id="stop_location_actions" class="transport-map-actions" hidden>'
                . '<button type="button" id="stop_location_locate" class="botaolistagem transport-button" aria-describedby="stop_location_locate_hint" hidden>Localizar pelo endereço</button>'
                . '<button type="button" id="stop_location_remove" class="transport-link-button" hidden>Remover localização</button>'
                . '<sub id="stop_location_locate_hint" class="modal-sub" hidden>Leva o marcador ao endereço preenchido acima. A localização só muda ao gravar.</sub>'
                . '</div>',
                false
            )
            ->assertSee('.transport-link-button { background: none; border: 0; padding: 4px 0; color: #1f6fb2; text-decoration: underline; font-size: 14px; cursor: pointer; }', false)
            ->assertSee('.route-path-note { margin: 8px 0; color: #555; }', false);

        $data = $this->pageData($response);

        $this->assertTrue($data['search']['enabled']);
        $this->assertSame('/module/Api/Ponto', $data['search']['endpoint']);
        $this->assertSame('localizacao', $data['search']['resource']);
    }

    public function test_page_without_key_has_no_locate_button_but_keeps_the_remove_link(): void
    {
        config([RoutePathService::KEY_SETTING => '']);

        $response = $this->page()
            ->assertSee('<div id="stop_location_actions" class="transport-map-actions" hidden><button type="button" id="stop_location_remove" class="transport-link-button" hidden>Remover localização</button></div>', false)
            ->assertDontSee('id="stop_location_locate"', false)
            ->assertDontSee('id="stop_location_locate_hint"', false);

        $this->assertFalse($this->pageData($response)['search']['enabled']);
    }

    public function test_page_data_brings_the_approved_messages(): void
    {
        $data = $this->pageData($this->page());

        $this->assertSame(['latitude' => 90, 'longitude' => 180], $data['limits']);
        $this->assertSame(6, $data['decimals']);
        $this->assertSame(['street' => 17, 'boundsMax' => 15, 'neighbourhood' => 15, 'city' => 13], $data['zoom']);
        $this->assertSame([
            'marked' => 'Localização marcada: {latitude}, {longitude}.',
            'markerTitle' => 'Localização do ponto',
            'missing' => 'Ponto sem localização. Clique no mapa para marcar.',
            'removal' => 'A localização será removida ao gravar.',
            'addressPlaceholder' => '{address}',
            'searching' => 'Procurando o endereço no mapa…',
            'addressFound' => 'Localização marcada pelo endereço {address}. Confira no mapa se o marcador está no lugar certo. Se não estiver, arraste o marcador ou clique no local exato.',
            'streetFound' => 'O número não foi encontrado, então o marcador foi colocado no meio da rua {address}. Arraste o marcador até o local exato do ponto. Se não for essa rua, clique no lugar certo do mapa.',
            'neighbourhoodFound' => 'O endereço foi encontrado só até o bairro {address}.',
            'cityFound' => 'O endereço foi encontrado só até a cidade {address}.',
            'regionHint' => 'O mapa mostra a região: clique no local exato para marcar o ponto.',
            'notFound' => 'Endereço não encontrado no mapa.',
            'notFoundHint' => 'Confira o endereço digitado ou clique no mapa para marcar o ponto.',
            'unavailable' => 'Não foi possível procurar o endereço no mapa agora.',
            'unavailableHint' => 'Clique no mapa para marcar o ponto.',
            'previousKept' => 'A localização anterior só será trocada ao gravar.',
            'markerKept' => 'O marcador não foi movido.',
            'adjusted' => 'Localização ajustada no mapa. Ela será salva ao clicar em Gravar.',
            'locate' => 'Localizar pelo endereço',
            'locating' => 'Localizando…',
            'locateHint' => 'Leva o marcador ao endereço preenchido acima. A localização só muda ao gravar.',
            'locateIncompleteHint' => 'Preencha o endereço para localizar pelo mapa.',
        ], $data['messages']);
        $this->assertSame([
            'address' => 'address',
            'street' => 'street',
            'neighbourhood' => 'neighbourhood',
            'city' => 'city',
            'notFound' => 'not_found',
        ], $data['search']['statuses']);
    }

    public function test_page_script_writes_the_service_address_as_text_and_never_as_html(): void
    {
        $script = file_get_contents(StopLocationMap::SCRIPT);

        $this->assertStringContainsString('strong.textContent = address', $script);
        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringNotContainsString('.html(', $script);
        $this->assertStringNotContainsString('botaolistagemdisabled', $script);
    }

    public function test_page_script_empties_the_screen_reader_status_when_the_marker_leaves_the_map(): void
    {
        $script = file_get_contents(StopLocationMap::SCRIPT);

        $this->assertStringContainsString("$('#stop_location_status').text(text);", $script);
        $this->assertStringContainsString("setStatus(messages.marked.replace('{latitude}', latitude).replace('{longitude}', longitude));", $script);
        $this->assertMatchesRegularExpression(
            "/function clearLocation\(\) \{[^}]*removeLayer\(state\.marker\);\s*\}\s*state\.marker = null;[^}]*setStatus\(''\);\s*\}/",
            $script
        );
        $this->assertSame(1, substr_count($script, "$('#stop_location_status')"));
    }

    public function test_page_script_treats_an_erased_city_text_as_no_city(): void
    {
        $script = file_get_contents(StopLocationMap::SCRIPT);

        $this->assertStringContainsString("return fieldText('#city_city') === '' ? '' : fieldText('#city_id');", $script);
        $this->assertStringContainsString("return hasPlace && cityId() !== '';", $script);
        $this->assertStringContainsString('city_id: cityId()', $script);
    }

    public function test_page_script_enables_the_search_with_the_street_or_only_the_neighbourhood(): void
    {
        $script = file_get_contents(StopLocationMap::SCRIPT);

        $this->assertStringContainsString("var hasPlace = fieldText('#address') !== '' || fieldText('#neighborhood') !== '';", $script);
        $this->assertStringContainsString("var SEARCH_TRIGGER_FIELDS = '#address, #number, #neighborhood';", $script);
        $this->assertStringContainsString('locate.disabled = !hasAddress();', $script);
    }

    public function test_page_data_brings_the_default_center_from_the_settings(): void
    {
        config([TransportMap::DEFAULT_LATITUDE_SETTING => '-5,0939', TransportMap::DEFAULT_LONGITUDE_SETTING => '-42.8369']);

        $this->assertSame([-5.0939, -42.8369], $this->pageData($this->page())['view']['center']);
    }

    public function test_page_data_has_no_center_when_only_one_setting_is_filled(): void
    {
        config([TransportMap::DEFAULT_LATITUDE_SETTING => '-5.0939', TransportMap::DEFAULT_LONGITUDE_SETTING => '']);

        $this->assertNull($this->pageData($this->page())['view']['center']);
    }

    public function test_page_data_brings_the_region_of_the_stops_with_coordinates(): void
    {
        DB::table('modules.ponto_transporte_escolar')->update(['latitude' => null, 'longitude' => null]);
        $this->stopRecord('Praça ENS009', ['latitude' => '-5.09', 'longitude' => '-42.83']);
        $this->stopRecord('Açude ENS009', ['latitude' => '-5.11', 'longitude' => '-42.84']);

        $this->assertSame([[-5.11, -42.84], [-5.09, -42.83]], $this->pageData($this->page())['view']['bounds']);
    }

    private function page(): TestResponse
    {
        return $this->get(self::PAGE)->assertSuccessful();
    }

    private function pageData(TestResponse $response): array
    {
        $this->assertSame(1, preg_match('/window\.stopLocationMap = (\{.*?\});\n/', $response->getContent(), $match));

        return json_decode($match[1], true);
    }
}
