<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use App\Setting;
use Database\Factories\LegacyUserFactory;
use iEducar\Packages\Transport\Services\RoutePathService;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class RoutePathEndpointTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions;

    public const ROUTE_API = '/module/Api/Rota';

    public const KEY = 'endpoint-key-ENS009';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenControllerIsTaken('RotaController', \ApiCoreController::class);

        $_GET = [];
        $_POST = [];
        $this->configureKey(self::KEY);
    }

    public function test_it_calculates_the_stops_sent_by_the_screen(): void
    {
        Http::fake([RoutePathService::DIRECTIONS_URL => Http::sequence()
            ->push($this->directions(12345, [['value' => 3, 'distance' => 10000]]))
            ->push($this->directions(6000, [['value' => 0, 'distance' => 6000]])),
        ]);

        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        [$farm, $road, $school] = $this->stopsWithCoordinates();
        $this->itineraryStopRecord($route, 1, $school, null, '06:30', 'I');

        $response = $this->requestPath($route, "{$farm}:I,{$road}:I,{$school}:I,{$school}:V,{$farm}:V")
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false)
            ->assertJsonPath('status', RoutePathService::STATUS_CALCULATED)
            ->assertJsonPath('outbound.km', 12.35)
            ->assertJsonPath('return.km', 6)
            ->assertJsonPath('km_paved', 10)
            ->assertJsonPath('km_unpaved', 8.35)
            ->assertJsonPath('stops.0.stop', $farm)
            ->assertJsonPath('stops.4.direction', 'V')
            ->assertJsonPath('outbound.geometry.type', 'LineString');

        $this->assertSame(64, strlen($response->json('hash')));
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => count($request['coordinates']) === 3);
    }

    public function test_without_stops_from_the_screen_it_uses_the_saved_itinerary(): void
    {
        Http::fake([RoutePathService::DIRECTIONS_URL => $this->directions(4200, [])]);

        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        [$farm, , $school] = $this->stopsWithCoordinates();
        $this->itineraryStopRecord($route, 1, $farm, null, '06:30', 'I');
        $this->itineraryStopRecord($route, 2, $school, null, '07:00', 'I');

        $this->requestPath($route)
            ->assertJsonPath('status', RoutePathService::STATUS_CALCULATED)
            ->assertJsonPath('outbound.km', 4.2)
            ->assertJsonPath('return.km', null)
            ->assertJsonCount(2, 'stops');
    }

    public function test_it_lists_the_stops_without_coordinates(): void
    {
        Http::fake();

        $route = $this->routeRecord('Rota do Açude ENS009', 2026);
        [$farm] = $this->stopsWithCoordinates();
        $dam = $this->stopRecord('Açude ENS009');

        $this->requestPath($route, "{$farm}:I,{$dam}:I")
            ->assertJsonPath('status', RoutePathService::STATUS_MISSING_COORDINATES)
            ->assertJsonPath('missing.0.stop', $dam)
            ->assertJsonPath('missing.0.name', 'Açude ENS009')
            ->assertJsonPath('missing.0.url', '/module/TransporteEscolar/Ponto?id=' . $dam)
            ->assertJsonCount(1, 'missing');

        Http::assertNothingSent();
    }

    public function test_without_key_it_asks_to_configure_it(): void
    {
        Http::fake();
        $this->configureKey(null);

        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        [$farm, $road] = $this->stopsWithCoordinates();

        $this->requestPath($route, "{$farm}:I,{$road}:I")
            ->assertJsonPath('status', RoutePathService::STATUS_MISSING_KEY)
            ->assertJsonPath('message', 'Configure a chave do serviço de rotas em Configurações de sistema.');

        Http::assertNothingSent();
    }

    public function test_a_service_failure_keeps_the_typed_distances(): void
    {
        Http::fake([RoutePathService::DIRECTIONS_URL => Http::response(['error' => 'Quota exceeded'], 429)]);

        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026, ['km_pav' => 12.5, 'km_npav' => 3]);
        [$farm, $road] = $this->stopsWithCoordinates();

        $this->requestPath($route, "{$farm}:I,{$road}:I")
            ->assertJsonPath('status', RoutePathService::STATUS_UNAVAILABLE)
            ->assertJsonPath('message', 'Não foi possível calcular o trajeto agora. Tente novamente mais tarde.');

        $saved = $this->route($route);
        $this->assertSame(12.5, (float) $saved->km_pav);
        $this->assertSame(3.0, (float) $saved->km_npav);
    }

    public function test_a_user_without_permission_on_routes_cannot_calculate(): void
    {
        Http::fake();

        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        [$farm, $road] = $this->stopsWithCoordinates();

        $this->requestPath($route, "{$farm}:I,{$road}:I", LegacyUserFactory::new()->institutional()->create())
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonMissingPath('status');

        Http::assertNothingSent();
    }

    public function test_an_unknown_route_is_rejected(): void
    {
        Http::fake();

        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);

        $this->requestPath($route + 100000, '')
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonMissingPath('status');

        Http::assertNothingSent();
    }

    private function configureKey(?string $key): void
    {
        Setting::query()->where('key', RoutePathService::KEY_SETTING)->update(['value' => $key]);
    }

    private function stopsWithCoordinates(): array
    {
        return [
            $this->stopRecord('Fazenda ENS009', ['latitude' => '-5.2001', 'longitude' => '-42.9001']),
            $this->stopRecord('Estrada ENS009', ['latitude' => '-5.1502', 'longitude' => '-42.8502']),
            $this->stopRecord('Escola ENS009', ['latitude' => '-5.0912', 'longitude' => '-42.8011']),
        ];
    }

    private function requestPath(int $route, ?string $stops = null, $user = null): TestResponse
    {
        $_GET = array_filter([
            'oper' => 'get',
            'resource' => 'trajeto',
            'id' => (string) $route,
            'pontos' => $stops,
        ], fn ($value) => $value !== null);

        return $this->actingAs($user ?? LegacyUserFactory::new()->admin()->create())
            ->get(self::ROUTE_API . '?' . http_build_query($_GET));
    }

    private function directions(float $meters, array $surfaces): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => [[-42.9, -5.2], [-42.85, -5.15], [-42.8, -5.09]]],
                'properties' => [
                    'summary' => ['distance' => $meters, 'duration' => 600],
                    'extras' => ['surface' => ['values' => [], 'summary' => $surfaces]],
                ],
            ]],
        ];
    }
}
