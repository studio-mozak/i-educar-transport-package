<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use App\Models\LegacyUser;
use App\Models\State;
use App\Setting;
use Database\Factories\CityFactory;
use Database\Factories\LegacyUserFactory;
use Database\Factories\MenuFactory;
use Database\Factories\StateFactory;
use iEducar\Packages\Transport\Services\AddressLocationService;
use iEducar\Packages\Transport\Services\RoutePathService;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use iEducar\Packages\Transport\Tests\SendsStopApiRequests;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';
require_once __DIR__ . '/../SendsStopApiRequests.php';

class StopAddressLocationEndpointTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions, SendsStopApiRequests;

    public const STOP_API = '/module/Api/Ponto';

    public const STOP_PROCESS = 21239;

    public const KEY = 'endpoint-key-ENS014';

    private const SEARCH_URL_PATTERN = AddressLocationService::SEARCH_URL . '*';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenControllerIsTaken('PontoController', \ApiCoreController::class);

        $_GET = [];
        $_POST = [];
        $this->configureKey(self::KEY);
    }

    public function test_it_returns_the_position_found_for_the_address_of_the_screen(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->addressFound())]);

        $response = $this->requestLocation($this->addressParams())
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false)
            ->assertJsonPath('status', AddressLocationService::STATUS_ADDRESS)
            ->assertJsonPath('label', 'Avenida Teresina, 500, Parque Piauí, Timon')
            ->assertJsonPath('latitude', '-5.09291')
            ->assertJsonPath('longitude', '-42.83378');

        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['address'] === 'Avenida Teresina, 500'
            && !isset($request['neighbourhood'])
            && $request['locality'] === 'Timon'
            && $request['region'] === 'MA'
            && $request->hasHeader('Authorization', self::KEY));
    }

    public function test_with_only_the_neighbourhood_and_city_it_returns_the_city_region(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->timonFallback())]);

        $this->requestLocation(array_merge($this->addressParams(), ['address' => '', 'number' => '']))
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false)
            ->assertJsonPath('status', AddressLocationService::STATUS_CITY)
            ->assertJsonPath('label', 'Timon')
            ->assertJsonPath('latitude', '-5.111174')
            ->assertJsonPath('longitude', '-42.858992');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => !isset($request['address'])
            && $request['neighbourhood'] === 'Parque Piauí'
            && $request['locality'] === 'Timon'
            && $request['region'] === 'MA');
    }

    public function test_it_saves_nothing(): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->addressFound())]);
        $stop = $this->stopRecord('Praça ENS014', ['latitude' => '-5.1', 'longitude' => '-42.1']);
        $stops = DB::table('modules.ponto_transporte_escolar')->count();

        $this->requestLocation(array_merge($this->addressParams(), ['id' => (string) $stop]))->assertSuccessful();

        $this->assertSame($stops, DB::table('modules.ponto_transporte_escolar')->count());
        $this->assertSame(['-5.1', '-42.1'], $this->stopCoordinates($stop));
    }

    public function test_without_key_it_answers_unavailable_without_calling_the_service(): void
    {
        Http::fake();
        $this->configureKey('');

        $this->requestLocation($this->addressParams())
            ->assertJsonPath('any_error_msg', false)
            ->assertJsonPath('status', AddressLocationService::STATUS_UNAVAILABLE);

        Http::assertNothingSent();
    }

    public function test_an_unknown_city_is_not_searched(): void
    {
        Http::fake();

        $this->requestLocation(array_merge($this->addressParams(), ['city_id' => '999999999']))
            ->assertJsonPath('status', AddressLocationService::STATUS_NOT_FOUND);

        Http::assertNothingSent();
    }

    public static function allowedFlags(): array
    {
        return [
            'can create' => [['visualiza' => 1, 'cadastra' => 1, 'edita' => 0, 'exclui' => 0]],
            'can edit' => [['visualiza' => 1, 'cadastra' => 0, 'edita' => 1, 'exclui' => 0]],
        ];
    }

    #[DataProvider('allowedFlags')]
    public function test_a_user_who_can_create_or_edit_stops_can_search(array $flags): void
    {
        Http::fake([self::SEARCH_URL_PATTERN => Http::response($this->addressFound())]);

        $this->requestLocation($this->addressParams(), $this->userWithStopFlags($flags))
            ->assertJsonPath('any_error_msg', false)
            ->assertJsonPath('status', AddressLocationService::STATUS_ADDRESS);
    }

    public function test_a_user_who_only_views_stops_cannot_search(): void
    {
        Http::fake();

        $this->requestLocation($this->addressParams(), $this->userWithStopFlags(['visualiza' => 1, 'cadastra' => 0, 'edita' => 0, 'exclui' => 0]))
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonPath('msgs.0.msg', \PontoController::LOCATE_FORBIDDEN_MESSAGE)
            ->assertJsonMissingPath('status');

        Http::assertNothingSent();
    }

    private function configureKey(string $key): void
    {
        Setting::query()->where('key', RoutePathService::KEY_SETTING)->update(['value' => $key]);
    }

    private function addressParams(): array
    {
        $state = State::query()->where('abbreviation', 'MA')->first() ?? StateFactory::new()->create(['abbreviation' => 'MA']);
        $city = CityFactory::new()->create(['name' => 'Timon', 'state_id' => $state->getKey()]);

        return [
            'address' => 'Avenida Teresina',
            'number' => '500',
            'neighborhood' => 'Parque Piauí',
            'city_id' => (string) $city->getKey(),
        ];
    }

    private function userWithStopFlags(array $flags): LegacyUser
    {
        return $this->userWithMenuFlags([MenuFactory::new()->create(['process' => self::STOP_PROCESS])->getKey() => $flags]);
    }

    private function requestLocation(array $params, ?LegacyUser $user = null): TestResponse
    {
        $_GET = array_merge(['oper' => 'get', 'resource' => AddressLocationService::API_RESOURCE], $params);

        return $this->actingAs($user ?? LegacyUserFactory::new()->admin()->create())
            ->get(self::STOP_API . '?' . http_build_query($_GET));
    }

    private function timonFallback(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [-42.858992, -5.111174]],
                'properties' => [
                    'layer' => 'locality',
                    'name' => 'Timon',
                    'confidence' => 0.6,
                    'match_type' => 'fallback',
                    'region_a' => 'MA',
                    'county' => 'Timon',
                    'localadmin' => 'Timon',
                    'locality' => 'Timon',
                ],
            ]],
        ];
    }

    private function addressFound(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [-42.83378, -5.09291]],
                'properties' => [
                    'layer' => 'address',
                    'housenumber' => '500',
                    'street' => 'Avenida Teresina',
                    'neighbourhood' => 'Parque Piauí',
                    'locality' => 'Timon',
                    'region_a' => 'MA',
                ],
            ]],
        ];
    }
}
