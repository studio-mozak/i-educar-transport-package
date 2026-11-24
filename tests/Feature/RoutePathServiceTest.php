<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Services\RoutePathService;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class RoutePathServiceTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions;

    public const KEY = 'test-key-ENS009';

    protected function setUp(): void
    {
        parent::setUp();

        config([RoutePathService::KEY_SETTING => self::KEY]);
    }

    public function test_it_sums_outbound_and_return_and_splits_paved_from_unpaved_road(): void
    {
        Http::fake([
            RoutePathService::DIRECTIONS_URL => Http::sequence()
                ->push($this->directions([[-42.81, -5.09], [-42.82, -5.10], [-42.83, -5.11]], 18400, [
                    ['value' => 3, 'distance' => 10000],
                    ['value' => 0, 'distance' => 5000],
                    ['value' => 8, 'distance' => 3400],
                ]))
                ->push($this->directions([[-42.83, -5.11], [-42.82, -5.10], [-42.81, -5.09]], 18900, [
                    ['value' => 1, 'distance' => 9100],
                    ['value' => 14, 'distance' => 1000],
                    ['value' => 2, 'distance' => 8800],
                ])),
        ]);

        $result = (new RoutePathService)->calculate($this->stops(['I', 'I', 'I', 'V', 'V', 'V']));

        $this->assertSame(RoutePathService::STATUS_CALCULATED, $result['status']);
        $this->assertSame(18.4, $result['outbound']['km']);
        $this->assertSame(18.9, $result['return']['km']);
        $this->assertSame(20.1, $result['km_paved']);
        $this->assertSame(17.2, $result['km_unpaved']);
        $this->assertSame(['type' => 'LineString', 'coordinates' => [[-42.81, -5.09], [-42.82, -5.10], [-42.83, -5.11]]], $result['outbound']['geometry']);
        $this->assertSame(RoutePathService::hash($result['stops']), $result['hash']);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($result['stops'], 'number'));
        $this->assertStringNotContainsString(self::KEY, json_encode($result));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === RoutePathService::DIRECTIONS_URL
            && $request->method() === 'POST'
            && $request->header('Authorization') === [self::KEY]
            && $request->header('Accept') === [RoutePathService::GEOJSON_MEDIA_TYPE]
            && $request['extra_info'] === ['surface']
            && $request['coordinates'] === [[-42.8, -5.0], [-42.81, -5.01], [-42.82, -5.02]]);
        Http::assertSent(fn (Request $request) => $request['coordinates'] === [[-42.83, -5.03], [-42.84, -5.04], [-42.85, -5.05]]);
    }

    public function test_unknown_road_and_missing_surface_count_as_unpaved(): void
    {
        Http::fake([
            RoutePathService::DIRECTIONS_URL => Http::sequence()
                ->push($this->directions([[-42.81, -5.09], [-42.82, -5.10]], 5000, [['value' => 0, 'distance' => 5000]]))
                ->push($this->directions([[-42.82, -5.10], [-42.81, -5.09]], 4000, null)),
        ]);

        $result = (new RoutePathService)->calculate($this->stops(['I', 'I', 'V', 'V']));

        $this->assertSame(0.0, $result['km_paved']);
        $this->assertSame(9.0, $result['km_unpaved']);
    }

    public function test_it_splits_a_direction_with_more_than_50_stops_into_chained_legs(): void
    {
        Http::fake([
            RoutePathService::DIRECTIONS_URL => Http::sequence()
                ->push($this->directions([[1, 1], [2, 2]], 1000, [['value' => 3, 'distance' => 1000]]))
                ->push($this->directions([[2, 2], [3, 3]], 2000, [['value' => 3, 'distance' => 2000]]))
                ->push($this->directions([[3, 3], [4, 4]], 3000, [['value' => 3, 'distance' => 3000]])),
        ]);

        $stops = $this->stops(array_fill(0, 120, 'I'));
        $result = (new RoutePathService)->calculate($stops);

        $this->assertSame(6.0, $result['outbound']['km']);
        $this->assertNull($result['return']['km']);
        $this->assertNull($result['return']['geometry']);
        $this->assertSame([[1.0, 1.0], [2.0, 2.0], [3.0, 3.0], [4.0, 4.0]], $result['outbound']['geometry']['coordinates']);

        $legs = [];
        Http::assertSent(function (Request $request) use (&$legs) {
            $legs[] = $request['coordinates'];

            return true;
        });

        $this->assertSame([50, 50, 22], array_map('count', $legs));
        $this->assertSame(end($legs[0]), $legs[1][0]);
        $this->assertSame(end($legs[1]), $legs[2][0]);
        $this->assertSame([(float) $stops[119]['longitude'], (float) $stops[119]['latitude']], end($legs[2]));
    }

    public function test_a_direction_with_a_single_stop_has_zero_km_and_no_line(): void
    {
        Http::fake([
            RoutePathService::DIRECTIONS_URL => $this->directions([[-42.81, -5.09], [-42.82, -5.10]], 2500, []),
        ]);

        $result = (new RoutePathService)->calculate($this->stops(['I', 'I', 'V']));

        $this->assertSame(2.5, $result['outbound']['km']);
        $this->assertSame(0.0, $result['return']['km']);
        $this->assertNull($result['return']['geometry']);
        Http::assertSentCount(1);
    }

    public function test_a_stop_without_coordinates_blocks_the_calculation_and_is_listed(): void
    {
        Http::fake();

        $stops = $this->stops(['I', 'I', 'V']);
        $stops[1]['latitude'] = null;
        $stops[2]['longitude'] = null;

        $result = (new RoutePathService)->calculate($stops);

        $this->assertSame(RoutePathService::STATUS_MISSING_COORDINATES, $result['status']);
        $this->assertSame(RoutePathService::MISSING_COORDINATES_MESSAGE, $result['message']);
        $this->assertSame([
            ['stop' => $stops[1]['stop'], 'name' => $stops[1]['name'], 'url' => '/module/TransporteEscolar/Ponto?id=' . $stops[1]['stop']],
            ['stop' => $stops[2]['stop'], 'name' => $stops[2]['name'], 'url' => '/module/TransporteEscolar/Ponto?id=' . $stops[2]['stop']],
        ], $result['missing']);
        Http::assertNothingSent();
    }

    public function test_without_key_it_does_not_call_the_service(): void
    {
        Http::fake();
        config([RoutePathService::KEY_SETTING => '  ']);

        $result = (new RoutePathService)->calculate($this->stops(['I', 'V']));

        $this->assertFalse(RoutePathService::hasKey());
        $this->assertSame(['status' => RoutePathService::STATUS_MISSING_KEY, 'message' => RoutePathService::MISSING_KEY_MESSAGE], $result);
        Http::assertNothingSent();
    }

    public function test_an_empty_itinerary_is_not_calculated(): void
    {
        Http::fake();

        $result = (new RoutePathService)->calculate([]);

        $this->assertSame(['status' => RoutePathService::STATUS_EMPTY, 'message' => RoutePathService::EMPTY_ITINERARY_MESSAGE], $result);
        Http::assertNothingSent();
    }

    public static function serviceFailures(): array
    {
        return [
            'quota exceeded' => [429, ['error' => 'Quota exceeded']],
            'server error' => [500, ['error' => 'Internal error']],
            'unroutable point' => [404, ['error' => ['code' => 2010]]],
            'body without route' => [200, ['type' => 'FeatureCollection', 'features' => []]],
        ];
    }

    #[DataProvider('serviceFailures')]
    public function test_a_service_failure_returns_the_unavailable_message(int $status, array $body): void
    {
        Http::fake([RoutePathService::DIRECTIONS_URL => Http::response($body, $status)]);

        $result = (new RoutePathService)->calculate($this->stops(['I', 'I']));

        $this->assertSame(['status' => RoutePathService::STATUS_UNAVAILABLE, 'message' => RoutePathService::UNAVAILABLE_MESSAGE], $result);
    }

    public function test_a_timeout_returns_the_unavailable_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $result = (new RoutePathService)->calculate($this->stops(['I', 'I']));

        $this->assertSame(['status' => RoutePathService::STATUS_UNAVAILABLE, 'message' => RoutePathService::UNAVAILABLE_MESSAGE], $result);
    }

    public function test_a_rejected_request_is_logged_with_status_and_service_error(): void
    {
        Log::spy();
        Http::fake([RoutePathService::DIRECTIONS_URL => Http::response(['error' => ['code' => 2007, 'message' => 'This response format is not supported']], 406)]);

        (new RoutePathService)->calculate($this->stops(['I', 'I']));

        Log::shouldHaveReceived('warning')->once()->with(RoutePathService::LOG_FAILURE_MESSAGE, [
            'reason' => 'http_error',
            'status' => 406,
            'code' => 2007,
            'message' => 'This response format is not supported',
        ]);
    }

    public function test_the_failure_log_never_contains_the_key(): void
    {
        $logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$logged) {
            $logged[] = [$message, $context];
        });
        Http::fake([RoutePathService::DIRECTIONS_URL => Http::response(['error' => 'Access denied for key ' . self::KEY], 403)]);

        (new RoutePathService)->calculate($this->stops(['I', 'I']));

        $this->assertCount(1, $logged);
        $this->assertSame(['reason' => 'http_error', 'status' => 403, 'code' => null, 'message' => 'Access denied for key ' . RoutePathService::REDACTED], $logged[0][1]);
        $this->assertStringNotContainsString(self::KEY, json_encode($logged));
        $this->assertStringNotContainsString('Authorization', json_encode($logged));
    }

    public function test_a_timeout_is_logged(): void
    {
        Log::spy();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        (new RoutePathService)->calculate($this->stops(['I', 'I']));

        Log::shouldHaveReceived('warning')->once()->with(RoutePathService::LOG_FAILURE_MESSAGE, ['reason' => 'connection']);
    }

    public function test_a_body_without_route_is_logged(): void
    {
        Log::spy();
        Http::fake([RoutePathService::DIRECTIONS_URL => Http::response(['type' => 'FeatureCollection', 'features' => []], 200)]);

        (new RoutePathService)->calculate($this->stops(['I', 'I']));

        Log::shouldHaveReceived('warning')->once()->with(RoutePathService::LOG_FAILURE_MESSAGE, ['reason' => 'invalid_body', 'status' => 200]);
    }

    public function test_hash_changes_when_order_direction_or_coordinate_changes(): void
    {
        $stops = $this->stops(['I', 'I', 'V']);
        $hash = RoutePathService::hash($stops);

        $reordered = [$stops[1], $stops[0], $stops[2]];
        $otherDirection = $stops;
        $otherDirection[2]['direction'] = 'I';
        $moved = $stops;
        $moved[0]['latitude'] = '-5.0001';

        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, RoutePathService::hash($stops));
        $this->assertNotSame($hash, RoutePathService::hash($reordered));
        $this->assertNotSame($hash, RoutePathService::hash($otherDirection));
        $this->assertNotSame($hash, RoutePathService::hash($moved));
    }

    public function test_hash_uses_the_normalized_coordinate_of_the_saved_stops(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        $school = $this->stopRecord('Escola ENS009', ['latitude' => '-5.0912', 'longitude' => '-42.8011']);
        $this->itineraryStopRecord($route, 1, $school, null, '06:30', 'I');
        $service = new RoutePathService;
        $hash = RoutePathService::hash($service->savedStops($route));

        $this->updateStop($school, '-5.091200', '-42.80110');
        $this->assertSame($hash, RoutePathService::hash($service->savedStops($route)));

        $this->updateStop($school, '-5.0912001', '-42.8011');
        $this->assertSame($hash, RoutePathService::hash($service->savedStops($route)));

        $this->updateStop($school, '-5.0913', '-42.8011');
        $this->assertNotSame($hash, RoutePathService::hash($service->savedStops($route)));
    }

    public function test_a_coordinate_out_of_range_counts_as_missing_location(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        $wrong = $this->stopRecord('Ponto errado ENS009', ['latitude' => '-95', 'longitude' => '-42.8']);
        $exponent = $this->stopRecord('Ponto com expoente ENS009', ['latitude' => '1e1', 'longitude' => '-42.8']);
        $this->itineraryStopRecord($route, 1, $wrong, null, '06:30', 'I');
        $this->itineraryStopRecord($route, 2, $exponent, null, '07:00', 'I');

        $stops = (new RoutePathService)->savedStops($route);

        $this->assertNull($stops[0]['latitude']);
        $this->assertNull($stops[1]['latitude']);
        $this->assertSame([$wrong, $exponent], array_column(RoutePathService::missingCoordinates($stops), 'stop'));
    }

    public function test_saved_stops_follow_the_itinerary_sequence_with_their_coordinates(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        $school = $this->stopRecord('Escola ENS009', ['latitude' => '-5.0912', 'longitude' => '-42.8011']);
        $farm = $this->stopRecord('Fazenda ENS009', ['latitude' => 'sem', 'longitude' => '-42.9']);
        $this->itineraryStopRecord($route, 2, $school, null, '07:00', 'I');
        $this->itineraryStopRecord($route, 1, $farm, null, '06:30', 'I');
        $this->itineraryStopRecord($route, 3, $school, null, '11:30', 'V');

        $this->assertSame([
            ['stop' => $farm, 'name' => 'Fazenda ENS009', 'direction' => 'I', 'latitude' => null, 'longitude' => '-42.9', 'number' => 1],
            ['stop' => $school, 'name' => 'Escola ENS009', 'direction' => 'I', 'latitude' => '-5.0912', 'longitude' => '-42.8011', 'number' => 2],
            ['stop' => $school, 'name' => 'Escola ENS009', 'direction' => 'V', 'latitude' => '-5.0912', 'longitude' => '-42.8011', 'number' => 3],
        ], (new RoutePathService)->savedStops($route));
    }

    public function test_stops_in_sequence_keep_the_screen_order_and_skip_unknown_stops(): void
    {
        $school = $this->stopRecord('Escola ENS009', ['latitude' => '-5.0912', 'longitude' => '-42.8011']);
        $farm = $this->stopRecord('Fazenda ENS009', ['latitude' => '-5.2', 'longitude' => '-42.9']);
        $unknown = $farm + 100000;

        $sequence = RoutePathService::sequenceFromInput("{$school}:V,{$unknown}:I,{$farm}:I");
        $stops = (new RoutePathService)->stopsInSequence($sequence);

        $this->assertSame([[$school, 'V', 1], [$farm, 'I', 2]], array_map(fn ($stop) => [$stop['stop'], $stop['direction'], $stop['number']], $stops));
        $this->assertSame("{$school}:V,{$farm}:I", RoutePathService::sequenceOf($stops));
    }

    public function test_stops_bounds_contain_every_stop_with_coordinates(): void
    {
        $this->stopRecord('Ponto do extremo sul ENS009', ['latitude' => '-33.75', 'longitude' => '-53.39']);
        $this->stopRecord('Ponto do extremo norte ENS009', ['latitude' => '5.27', 'longitude' => '-60.21']);
        $this->stopRecord('Ponto sem localização ENS009', ['latitude' => 'x', 'longitude' => '']);

        [[$south, $west], [$north, $east]] = (new RoutePathService)->stopsBounds();

        $this->assertLessThanOrEqual(-33.75, $south);
        $this->assertLessThanOrEqual(-60.21, $west);
        $this->assertGreaterThanOrEqual(5.27, $north);
        $this->assertGreaterThanOrEqual(-53.39, $east);
    }

    public function test_stops_bounds_ignore_a_stop_with_a_coordinate_out_of_range(): void
    {
        $this->stopRecord('Ponto do extremo sul ENS009', ['latitude' => '-33.75', 'longitude' => '-53.39']);
        $this->stopRecord('Latitude fora da faixa ENS009', ['latitude' => '-95', 'longitude' => '-42.8']);
        $this->stopRecord('Longitude fora da faixa ENS009', ['latitude' => '-89.5', 'longitude' => '-542']);
        $this->stopRecord('Longitude fora da faixa a leste ENS009', ['latitude' => '89.5', 'longitude' => '200']);

        [[$south, $west], [$north, $east]] = (new RoutePathService)->stopsBounds();

        $this->assertLessThanOrEqual(-33.75, $south);
        $this->assertGreaterThan(-89.5, $south);
        $this->assertLessThan(89.5, $north);
        $this->assertGreaterThanOrEqual(-180, $west);
        $this->assertLessThanOrEqual(180, $east);
    }

    public static function sequences(): array
    {
        return [
            'absent' => [null, null],
            'empty' => ['', []],
            'with sql' => ['1:I;DROP TABLE x', []],
            'wrong direction' => ['1:X', []],
            'array' => [['1:I'], []],
            'valid' => ['12:I,7:V', [['stop' => 12, 'direction' => 'I'], ['stop' => 7, 'direction' => 'V']]],
        ];
    }

    #[DataProvider('sequences')]
    public function test_sequence_from_input(mixed $input, ?array $expected): void
    {
        $this->assertSame($expected, RoutePathService::sequenceFromInput($input));
    }

    public static function distances(): array
    {
        return [
            'empty clears' => ['', null],
            'empty converted to null clears' => [null, null],
            'comma' => ['18,40', 18.4],
            'thousands' => ['1.234,56', 1234.56],
            'integer' => ['55', 55.0],
            'negative' => ['-1,00', false],
            'text' => ['abc', false],
            'array' => [['1'], false],
            'too long' => ['100.000,01', false],
        ];
    }

    #[DataProvider('distances')]
    public function test_distance_from_input(mixed $input, float|null|false $expected): void
    {
        $this->assertSame($expected, RoutePathService::distanceFromInput($input));
    }

    public function test_path_from_input_accepts_only_line_strings_and_a_sha256_hash(): void
    {
        $hash = str_repeat('a', 64);
        $line = json_encode(['type' => 'LineString', 'coordinates' => [[-42.81, -5.09], [-42.82, -5.10]]]);

        $path = RoutePathService::pathFromInput($line, '', $hash);

        $this->assertSame('{"type":"LineString","coordinates":[[-42.81,-5.09],[-42.82,-5.1]]}', $path['trajeto_ida']);
        $this->assertNull($path['trajeto_volta']);
        $this->assertSame($hash, $path['trajeto_hash']);
        $this->assertNotNull($path['trajeto_calculado_em']);

        $this->assertNull(RoutePathService::pathFromInput($line, '', 'not-a-hash'));
        $this->assertNull(RoutePathService::pathFromInput('{"type":"Point","coordinates":[1,2]}', '', $hash));
        $this->assertNull(RoutePathService::pathFromInput('{"type":"LineString","coordinates":[[1,2]]}', '', $hash));
        $this->assertNull(RoutePathService::pathFromInput('{"type":"LineString","coordinates":[[1,2],[500,2]]}', '', $hash));
        $this->assertNull(RoutePathService::pathFromInput('{"type":"LineString","coordinates":[[1,2],["<script>",2]]}', '', $hash));
        $this->assertNull(RoutePathService::pathFromInput($line, 'not json', $hash));
    }

    private function updateStop(int $stop, string $latitude, string $longitude): void
    {
        DB::table('modules.ponto_transporte_escolar')
            ->where('cod_ponto_transporte_escolar', $stop)
            ->update(['latitude' => $latitude, 'longitude' => $longitude]);
    }

    private function stops(array $directions): array
    {
        return array_map(fn (string $direction, int $index) => [
            'stop' => 1000 + $index,
            'name' => 'Ponto ' . $index . ' ENS009',
            'direction' => $direction,
            'latitude' => (string) (-5 - $index / 100),
            'longitude' => (string) (-42.8 - $index / 100),
            'number' => $index + 1,
        ], $directions, array_keys($directions));
    }

    private function directions(array $coordinates, float $meters, ?array $surfaces): array
    {
        $properties = ['summary' => ['distance' => $meters, 'duration' => 600]];

        if ($surfaces !== null) {
            $properties['extras'] = ['surface' => ['values' => [], 'summary' => $surfaces]];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
                'properties' => $properties,
            ]],
        ];
    }
}
