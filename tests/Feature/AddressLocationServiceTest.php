<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Services\AddressLocationService;
use iEducar\Packages\Transport\Services\RoutePathService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AddressLocationServiceTest extends TestCase
{
    public const KEY = 'geocode-key-ENS014';

    private const SEARCH_URL_PATTERN = AddressLocationService::SEARCH_URL . '*';

    private const VIEW = ['bounds' => null, 'center' => [-5.0939, -42.8369]];

    private const ADDRESS = [
        'address' => 'Avenida Teresina',
        'number' => '500',
        'neighborhood' => 'Parque Piauí',
        'city' => 'Timon',
        'state' => 'MA',
    ];

    private const NEIGHBOURHOOD_ONLY = [
        'address' => '',
        'number' => '500',
        'neighborhood' => 'Parque Piauí',
        'city' => 'Timon',
        'state' => 'MA',
    ];

    private const TIMON_FALLBACK = [
        'name' => 'Timon',
        'confidence' => 0.6,
        'match_type' => 'fallback',
        'region_a' => 'MA',
        'county' => 'Timon',
        'localadmin' => 'Timon',
        'locality' => 'Timon',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([RoutePathService::KEY_SETTING => self::KEY]);
    }

    public function test_it_searches_each_part_of_the_address_in_its_own_field_focused_on_the_default_center_with_the_key_in_the_header(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('address', [-42.83378, -5.09291], [
            'housenumber' => '500',
            'street' => 'Avenida Teresina',
            'neighbourhood' => 'Parque Piauí',
            'locality' => 'Timon',
        ]))]);

        $this->service()->locate(self::ADDRESS);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return str_starts_with($request->url(), AddressLocationService::SEARCH_URL . '?')
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization', self::KEY)
                && $request['address'] === 'Avenida Teresina, 500'
                && !isset($request['neighbourhood'])
                && $request['locality'] === 'Timon'
                && $request['region'] === 'MA'
                && $request['country'] === 'BR'
                && (string) $request['focus.point.lat'] === '-5.0939'
                && (string) $request['focus.point.lon'] === '-42.8369'
                && (string) $request['size'] === '5'
                && !isset($request['text']);
        });
    }

    public function test_with_street_the_neighbourhood_is_not_sent_and_the_real_exact_address_is_found(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('address', [-42.83378, -5.09291], [
            'name' => 'Avenida Teresina 500',
            'housenumber' => '500',
            'street' => 'Avenida Teresina',
            'confidence' => 1,
            'match_type' => 'exact',
            'region_a' => 'MA',
            'county' => 'Timon',
            'localadmin' => 'Timon',
            'locality' => 'Timon',
            'label' => 'Avenida Teresina 500, Timon, MA, Brazil',
        ]))]);

        $this->assertSame([
            'status' => AddressLocationService::STATUS_ADDRESS,
            'label' => 'Avenida Teresina, 500, Timon',
            'latitude' => '-5.09291',
            'longitude' => '-42.83378',
        ], $this->service()->locate(self::ADDRESS));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['address'] === 'Avenida Teresina, 500'
            && !isset($request['neighbourhood'])
            && $request['locality'] === 'Timon'
            && $request['region'] === 'MA');
    }

    public function test_empty_number_and_neighbourhood_are_not_sent(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response(['type' => 'FeatureCollection', 'features' => []])]);

        $this->service()->locate(array_merge(self::ADDRESS, ['number' => ' ', 'neighborhood' => null]));

        Http::assertSent(function (Request $request) {
            return $request['address'] === 'Avenida Teresina'
                && !isset($request['neighbourhood'])
                && $request['locality'] === 'Timon';
        });
    }

    public function test_without_default_center_it_focuses_on_the_middle_of_the_stops_region(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response(['type' => 'FeatureCollection', 'features' => []])]);

        (new AddressLocationService(['bounds' => [[-5.2, -42.9], [-5.0, -42.7]], 'center' => null]))->locate(self::ADDRESS);

        Http::assertSent(fn (Request $request) => abs((float) $request['focus.point.lat'] + 5.1) < 0.000001
            && abs((float) $request['focus.point.lon'] + 42.8) < 0.000001);
    }

    public function test_without_any_default_view_it_sends_no_focus_point(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response(['type' => 'FeatureCollection', 'features' => []])]);

        (new AddressLocationService(['bounds' => null, 'center' => null]))->locate(self::ADDRESS);

        Http::assertSent(fn (Request $request) => !str_contains($request->url(), 'focus.point'));
    }

    public function test_a_house_number_result_brings_the_position_and_the_address_understood_by_the_service(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('address', [-42.83378, -5.09291], [
            'housenumber' => '500',
            'street' => 'Avenida Teresina',
            'neighbourhood' => 'Parque Piauí',
            'locality' => 'Timon',
            'region_a' => 'MA',
        ]))]);

        $this->assertSame([
            'status' => AddressLocationService::STATUS_ADDRESS,
            'label' => 'Avenida Teresina, 500, Parque Piauí, Timon',
            'latitude' => '-5.09291',
            'longitude' => '-42.83378',
        ], $this->service()->locate(self::ADDRESS));
    }

    public function test_a_street_result_brings_the_middle_of_the_street(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('street', [-42.8211, -5.1012], [
            'name' => 'Rua Tenente Antônio Correia',
            'street' => 'Rua Tenente Antônio Correia',
            'locality' => 'Timon',
            'region_a' => 'MA',
        ]))]);

        $this->assertSame([
            'status' => AddressLocationService::STATUS_STREET,
            'label' => 'Rua Tenente Antônio Correia, Timon',
            'latitude' => '-5.1012',
            'longitude' => '-42.8211',
        ], $this->service()->locate(self::ADDRESS));
    }

    public function test_a_neighbourhood_result_brings_the_region_and_the_neighbourhood_with_the_city(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('neighbourhood', [-42.84, -5.12], [
            'name' => 'Parque Piauí',
            'neighbourhood' => 'Parque Piauí',
            'locality' => 'Timon',
            'region_a' => 'MA',
        ], [-42.86, -5.14, -42.82, -5.10]))]);

        $this->assertSame([
            'status' => AddressLocationService::STATUS_NEIGHBOURHOOD,
            'label' => 'Parque Piauí, Timon',
            'latitude' => '-5.12',
            'longitude' => '-42.84',
            'bounds' => [[-5.14, -42.86], [-5.1, -42.82]],
        ], $this->service()->locate(self::ADDRESS));
    }

    public static function cityLayers(): array
    {
        return [
            'locality' => ['locality'],
            'localadmin' => ['localadmin'],
            'county' => ['county'],
        ];
    }

    #[DataProvider('cityLayers')]
    public function test_a_typed_street_answered_only_by_the_city_returns_not_found(string $layer): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode($layer, [-42.8369, -5.0939], [
            'name' => 'Timon',
            $layer => 'Timon',
            'region_a' => 'MA',
        ], [-42.95, -5.2, -42.7, -4.95]))]);

        $this->assertSame(['status' => AddressLocationService::STATUS_NOT_FOUND], $this->service()->locate(self::ADDRESS));
    }

    public static function realStreetsOnlyAnsweredByTheCity(): array
    {
        return [
            'invented street' => [['address' => 'Xyzzyqwq Plugh', 'number' => null, 'neighborhood' => null]],
            'street with the neighbourhood' => [['address' => 'Rua Um', 'number' => null, 'neighborhood' => 'Vila Angélica']],
            'neighbourhood typed as the street' => [['address' => 'Parque Piauí', 'number' => null, 'neighborhood' => 'Parque Piauí']],
        ];
    }

    #[DataProvider('realStreetsOnlyAnsweredByTheCity')]
    public function test_the_real_city_fallback_of_the_service_for_a_typed_street_returns_not_found(array $typed): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('locality', [-42.858992, -5.111174], self::TIMON_FALLBACK))]);

        $this->assertSame(
            ['status' => AddressLocationService::STATUS_NOT_FOUND],
            $this->service()->locate(array_merge(self::ADDRESS, $typed))
        );
    }

    public function test_a_real_street_found_without_the_number_is_still_the_street(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('street', [-42.836872, -5.094559], [
            'name' => 'Avenida Teresina',
            'street' => 'Avenida Teresina',
            'confidence' => 0.8,
            'match_type' => 'fallback',
            'region_a' => 'MA',
            'county' => 'Timon',
            'localadmin' => 'Timon',
            'locality' => 'Timon',
        ]))]);

        $this->assertSame([
            'status' => AddressLocationService::STATUS_STREET,
            'label' => 'Avenida Teresina, Timon',
            'latitude' => '-5.094559',
            'longitude' => '-42.836872',
        ], $this->service()->locate(array_merge(self::ADDRESS, ['number' => '99999', 'neighborhood' => null])));
    }

    public function test_a_real_exact_street_is_the_street(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('street', [-42.841472, -5.09014], [
            'name' => 'Rua Tenente Antonio Correia da Silva',
            'street' => 'Rua Tenente Antonio Correia da Silva',
            'confidence' => 1,
            'match_type' => 'exact',
            'region_a' => 'MA',
            'county' => 'Timon',
            'localadmin' => 'Timon',
            'locality' => 'Timon',
        ]))]);

        $this->assertSame([
            'status' => AddressLocationService::STATUS_STREET,
            'label' => 'Rua Tenente Antonio Correia da Silva, Timon',
            'latitude' => '-5.09014',
            'longitude' => '-42.841472',
        ], $this->service()->locate(array_merge(self::ADDRESS, ['address' => 'Rua Tenente Antônio Correia', 'number' => null, 'neighborhood' => null])));
    }

    public function test_the_city_fallback_is_skipped_for_a_street_found_later(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->collection([
            $this->feature('locality', [-42.858992, -5.111174], self::TIMON_FALLBACK),
            $this->feature('street', [-42.836872, -5.094559], ['street' => 'Avenida Teresina', 'locality' => 'Timon', 'region_a' => 'MA']),
        ]))]);

        $result = $this->service()->locate(self::ADDRESS);

        $this->assertSame(AddressLocationService::STATUS_STREET, $result['status']);
        $this->assertSame('-5.094559', $result['latitude']);
    }

    public static function notFoundBodies(): array
    {
        return [
            'no feature' => [['type' => 'FeatureCollection', 'features' => []]],
            'only the state' => [['type' => 'FeatureCollection', 'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [-45.0, -5.0]],
                'properties' => ['layer' => 'region', 'name' => 'Maranhão'],
            ]]]],
        ];
    }

    #[DataProvider('notFoundBodies')]
    public function test_nothing_useful_found_returns_not_found(array $body): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($body)]);

        $this->assertSame(['status' => AddressLocationService::STATUS_NOT_FOUND], $this->service()->locate(self::ADDRESS));
    }

    public static function incompleteAddresses(): array
    {
        return [
            'without street and neighbourhood' => [array_merge(self::ADDRESS, ['address' => '  ', 'neighborhood' => ''])],
            'only the number' => [array_merge(self::ADDRESS, ['address' => null, 'neighborhood' => null])],
            'without city' => [array_merge(self::ADDRESS, ['city' => null])],
            'neighbourhood without city' => [array_merge(self::NEIGHBOURHOOD_ONLY, ['city' => ' '])],
            'without state' => [array_merge(self::ADDRESS, ['state' => ''])],
        ];
    }

    #[DataProvider('incompleteAddresses')]
    public function test_an_incomplete_address_is_not_searched(array $address): void
    {
        Http::fake();

        $this->assertSame(['status' => AddressLocationService::STATUS_NOT_FOUND], $this->service()->locate($address));
        Http::assertNothingSent();
    }

    public function test_without_street_it_searches_only_the_neighbourhood_in_the_city(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response(['type' => 'FeatureCollection', 'features' => []])]);

        $this->service()->locate(self::NEIGHBOURHOOD_ONLY);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => !isset($request['address'])
            && $request['neighbourhood'] === 'Parque Piauí'
            && $request['locality'] === 'Timon'
            && $request['region'] === 'MA'
            && $request['country'] === 'BR'
            && (string) $request['size'] === '5');
    }

    public function test_the_real_city_answer_for_only_the_neighbourhood_is_the_city_region_without_marker(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('locality', [-42.858992, -5.111174], self::TIMON_FALLBACK))]);

        $this->assertSame([
            'status' => AddressLocationService::STATUS_CITY,
            'label' => 'Timon',
            'latitude' => '-5.111174',
            'longitude' => '-42.858992',
            'bounds' => null,
        ], $this->service()->locate(self::NEIGHBOURHOOD_ONLY));
    }

    #[DataProvider('cityLayers')]
    public function test_without_street_each_city_layer_is_the_city_region_with_its_box(string $layer): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode($layer, [-42.8369, -5.0939], [
            'name' => 'Timon',
            $layer => 'Timon',
            'region_a' => 'MA',
        ], [-42.95, -5.2, -42.7, -4.95]))]);

        $result = $this->service()->locate(self::NEIGHBOURHOOD_ONLY);

        $this->assertSame(AddressLocationService::STATUS_CITY, $result['status']);
        $this->assertSame([[-5.2, -42.95], [-4.95, -42.7]], $result['bounds']);
    }

    public function test_the_real_neighbourhood_answer_for_only_the_neighbourhood_is_the_neighbourhood_region(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('neighbourhood', [-42.89669, -5.12354], array_merge(
            self::TIMON_FALLBACK,
            ['name' => 'Parque Piauí']
        )))]);

        $this->assertSame([
            'status' => AddressLocationService::STATUS_NEIGHBOURHOOD,
            'label' => 'Parque Piauí, Timon',
            'latitude' => '-5.12354',
            'longitude' => '-42.89669',
            'bounds' => null,
        ], $this->service()->locate(self::NEIGHBOURHOOD_ONLY));
    }

    public static function neighbourhoodsOfAnotherCity(): array
    {
        return [
            'real answer from Teresina' => [['locality', [-42.8, -5.13333], [
                'name' => 'Parque Piaui',
                'confidence' => 0.6,
                'match_type' => 'fallback',
                'region_a' => 'PI',
                'county' => 'Teresina Municipality',
                'locality' => 'Parque Piaui',
            ]]],
            'neighbourhood of Teresina' => [['neighbourhood', [-42.8, -5.1], [
                'name' => 'Parque Piauí',
                'locality' => 'Teresina',
                'region_a' => 'PI',
            ]]],
        ];
    }

    #[DataProvider('neighbourhoodsOfAnotherCity')]
    public function test_only_a_neighbourhood_found_in_another_city_returns_not_found(array $result): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode(...$result))]);

        $this->assertSame(['status' => AddressLocationService::STATUS_NOT_FOUND], $this->service()->locate(self::NEIGHBOURHOOD_ONLY));
    }

    public function test_without_street_a_street_or_address_result_is_skipped_for_the_region(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->collection([
            $this->feature('address', [-42.83378, -5.09291], ['housenumber' => '500', 'street' => 'Avenida Teresina', 'locality' => 'Timon', 'region_a' => 'MA']),
            $this->feature('street', [-42.8211, -5.1012], ['street' => 'Rua Tenente Antônio Correia', 'locality' => 'Timon', 'region_a' => 'MA']),
            $this->feature('locality', [-42.858992, -5.111174], self::TIMON_FALLBACK),
        ]))]);

        $this->assertSame(AddressLocationService::STATUS_CITY, $this->service()->locate(self::NEIGHBOURHOOD_ONLY)['status']);
    }

    public function test_without_key_it_does_not_call_the_service(): void
    {
        Http::fake();
        config([RoutePathService::KEY_SETTING => ' ']);

        $this->assertSame(['status' => AddressLocationService::STATUS_UNAVAILABLE], $this->service()->locate(self::ADDRESS));
        Http::assertNothingSent();
    }

    public static function serviceFailures(): array
    {
        return [
            'quota exceeded' => [429, ['error' => 'Quota exceeded']],
            'server error' => [500, ['error' => 'Internal error']],
            'refused key' => [403, ['error' => 'Access to this API has been disallowed']],
            'body without features' => [200, ['type' => 'FeatureCollection']],
            'feature without coordinates' => [200, ['type' => 'FeatureCollection', 'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => ['x']],
                'properties' => ['layer' => 'address', 'locality' => 'Timon', 'region_a' => 'MA'],
            ]]]],
        ];
    }

    #[DataProvider('serviceFailures')]
    public function test_a_service_failure_returns_unavailable(int $status, array $body): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($body, $status)]);

        $this->assertSame(['status' => AddressLocationService::STATUS_UNAVAILABLE], $this->service()->locate(self::ADDRESS));
    }

    public function test_a_timeout_returns_unavailable_and_is_logged(): void
    {
        Log::spy();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->assertSame(['status' => AddressLocationService::STATUS_UNAVAILABLE], $this->service()->locate(self::ADDRESS));
        Log::shouldHaveReceived('warning')->once()->with(AddressLocationService::LOG_FAILURE_MESSAGE, ['reason' => 'connection']);
    }

    public function test_the_failure_log_never_contains_the_key(): void
    {
        $logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$logged) {
            $logged[] = [$message, $context];
        });
        Http::fake([self::SEARCH_URL_PATTERN => Http::response(['error' => 'Access denied for key ' . self::KEY], 403)]);

        $result = $this->service()->locate(self::ADDRESS);

        $this->assertCount(1, $logged);
        $this->assertSame(AddressLocationService::LOG_FAILURE_MESSAGE, $logged[0][0]);
        $this->assertSame(['reason' => 'http_error', 'status' => 403, 'code' => null, 'message' => 'Access denied for key ' . RoutePathService::REDACTED], $logged[0][1]);
        $this->assertStringNotContainsString(self::KEY, json_encode($logged));
        $this->assertStringNotContainsString('Authorization', json_encode($logged));
        $this->assertStringNotContainsString(self::KEY, json_encode($result));
    }

    public function test_a_body_without_features_is_logged(): void
    {
        Log::spy();
        Http::fake([self::SEARCH_URL_PATTERN => Http::response(['type' => 'FeatureCollection'], 200)]);

        $this->service()->locate(self::ADDRESS);

        Log::shouldHaveReceived('warning')->once()->with(AddressLocationService::LOG_FAILURE_MESSAGE, ['reason' => 'invalid_body', 'status' => 200]);
    }

    public function test_a_result_from_another_city_is_skipped_for_the_first_one_in_the_registered_city(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->collection([
            $this->feature('locality', [-42.8, -5.13333], [
                'name' => 'Parque Piaui',
                'locality' => 'Parque Piaui',
                'county' => 'Teresina Municipality',
                'region_a' => 'PI',
            ]),
            $this->feature('neighbourhood', [-42.8967, -5.1235], [
                'name' => 'Parque Piauí',
                'neighbourhood' => 'Parque Piauí',
                'locality' => 'Timon',
                'region_a' => 'MA',
            ]),
        ]))]);

        $this->assertSame([
            'status' => AddressLocationService::STATUS_NEIGHBOURHOOD,
            'label' => 'Parque Piauí, Timon',
            'latitude' => '-5.1235',
            'longitude' => '-42.8967',
            'bounds' => null,
        ], $this->service()->locate(array_merge(self::ADDRESS, ['address' => 'Parque Piauí', 'number' => null])));
    }

    public static function resultsOutsideTheCity(): array
    {
        return [
            'same city name in another state' => [[
                ['locality', ['name' => 'Timon', 'locality' => 'Timon', 'region_a' => 'PI']],
            ]],
            'other cities' => [[
                ['address', ['housenumber' => '500', 'street' => 'Avenida Teresina', 'locality' => 'Caxias', 'region_a' => 'MA']],
                ['street', ['street' => 'Avenida Teresina', 'locality' => 'Teresina', 'region_a' => 'PI']],
                ['locality', ['name' => 'Parque Piaui', 'locality' => 'Parque Piaui', 'region_a' => 'PI']],
            ]],
            'neighbourhood of another city' => [[
                ['neighbourhood', ['name' => 'Parque Piauí', 'locality' => 'Teresina', 'region_a' => 'PI']],
            ]],
            'without city or state' => [[
                ['address', ['housenumber' => '500', 'street' => 'Avenida Teresina']],
                ['locality', ['name' => 'Timon', 'locality' => 'Timon']],
            ]],
        ];
    }

    #[DataProvider('resultsOutsideTheCity')]
    public function test_when_no_result_is_in_the_registered_city_it_returns_not_found(array $results): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->collection(array_map(
            fn (array $result) => $this->feature($result[0], [-42.8, -5.1], $result[1]),
            $results
        )))]);

        $this->assertSame(['status' => AddressLocationService::STATUS_NOT_FOUND], $this->service()->locate(self::ADDRESS));
    }

    public function test_the_city_and_state_comparison_ignores_accents_case_and_spaces(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('street', [-44.3028, -2.5297], [
            'street' => 'Rua Grande',
            'locality' => 'SAO  LUIS',
            'region_a' => 'ma',
        ]))]);

        $result = $this->service()->locate(array_merge(self::ADDRESS, ['address' => 'Rua Grande', 'city' => ' São Luís ']));

        $this->assertSame(AddressLocationService::STATUS_STREET, $result['status']);
        $this->assertSame('-2.5297', $result['latitude']);
    }

    public function test_the_city_falls_back_to_localadmin_or_county_when_locality_is_empty(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->geocode('street', [-42.8211, -5.1012], [
            'street' => 'Rua Tenente Antônio Correia',
            'locality' => '',
            'county' => 'Timon',
            'region_a' => 'MA',
        ]))]);

        $this->assertSame(AddressLocationService::STATUS_STREET, $this->service()->locate(self::ADDRESS)['status']);
    }

    public function test_a_result_layer_that_is_not_used_is_skipped_for_the_next_one(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->collection([
            $this->feature('venue', [-42.89, -5.12], ['name' => 'UBS - Parque Piauí', 'locality' => 'Timon', 'region_a' => 'MA']),
            $this->feature('neighbourhood', [-42.89669, -5.12354], ['name' => 'Parque Piauí', 'locality' => 'Timon', 'region_a' => 'MA']),
        ]))]);

        $result = $this->service()->locate(self::ADDRESS);

        $this->assertSame(AddressLocationService::STATUS_NEIGHBOURHOOD, $result['status']);
        $this->assertSame('-5.12354', $result['latitude']);
    }

    private function service(): AddressLocationService
    {
        return new AddressLocationService(self::VIEW);
    }

    private function geocode(string $layer, array $coordinates, array $properties, ?array $bbox = null): array
    {
        return $this->collection([$this->feature($layer, $coordinates, $properties, $bbox)]);
    }

    private function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    private function feature(string $layer, array $coordinates, array $properties, ?array $bbox = null): array
    {
        $feature = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => $coordinates],
            'properties' => array_merge(['layer' => $layer, 'country' => 'Brasil'], $properties),
        ];

        if ($bbox !== null) {
            $feature['bbox'] = $bbox;
        }

        return $feature;
    }
}
