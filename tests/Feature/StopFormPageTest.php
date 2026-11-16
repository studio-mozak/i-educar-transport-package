<?php

namespace iEducar\Packages\Transport\Tests\Feature;

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

    public function test_page_shows_editable_coordinates_with_hint_and_placeholder_before_the_map(): void
    {
        $response = $this->page()
            ->assertSeeInOrder([
                'Latitude', 'De -90 a 90', 'name="latitude" id="latitude"',
                'Longitude', 'De -180 a 180', 'name="longitude" id="longitude"',
                '<tr id="tr_stop_location"><td colspan="2" class="formlttd"><div class="stop-location"><span class="form"><b>Localização no mapa</b></span>',
                '<div class="stop-location-help"><sub class="modal-sub">' . StopLocationMap::HELP . '</sub></div>',
                '<div id="stop_location_map" class="transport-map stop-location-map" role="region" aria-label="Mapa para marcar a localização do ponto"></div>',
                '<div id="stop_location_missing" class="route-path-alert route-path-alert-neutral" role="status" hidden>Ponto sem localização. Clique no mapa para marcar.</div>',
                '<div id="stop_location_removal" class="route-path-alert route-path-alert-notice" role="status" hidden>A localização será removida ao gravar.</div>',
                '<div id="stop_location_status" class="sr-only" aria-live="polite"></div>',
            ], false)
            ->assertSee("\$input.attr('placeholder', 'Ex.: -5.091234');", false)
            ->assertSee("\$input.attr('placeholder', 'Ex.: -42.837512');", false)
            ->assertSee('window.stopLocationMap = ', false)
            ->assertSee('window.transportMap = {', false)
            ->assertSee('.stop-location-map { height: 360px; cursor: crosshair; }', false)
            ->assertSee('.transport-coordinate-error { display: block; margin-top: 4px; color: #c0392b; }', false);

        $this->assertDoesNotMatchRegularExpression('/id="(latitude|longitude)"[^>]*(readonly|disabled)/', $response->getContent());
        $this->assertMatchesRegularExpression('/id="latitude"[^>]*autocomplete="off"/', $response->getContent());
    }

    public function test_page_data_brings_the_messages_of_the_coordinate_rule(): void
    {
        $data = $this->pageData($this->page());

        $this->assertSame(['latitude' => 90, 'longitude' => 180], $data['limits']);
        $this->assertSame(6, $data['decimals']);
        $this->assertSame(['street' => 17, 'boundsMax' => 15], $data['zoom']);
        $this->assertSame(\clsModulesPontoTransporteEscolar::COORDINATES_TOGETHER_MESSAGE, $data['messages']['together']);
        $this->assertSame('Localização marcada: {latitude}, {longitude}.', $data['messages']['marked']);
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
