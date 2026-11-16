<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use App\Models\Place;
use Database\Factories\CityFactory;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Api\DiarioApiRequestTestTrait;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class StopApiAddressTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions, DiarioApiRequestTestTrait;

    public const STOP_API = '/module/Api/Ponto';

    private const ADDRESS_COLUMNS = ['address', 'number', 'complement', 'neighborhood', 'city_id', 'postal_code'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenControllerIsTaken('PontoController', \ApiCoreController::class);

        $_GET = [];
        $_POST = [];
    }

    public function test_put_with_only_the_coordinate_keeps_the_saved_address(): void
    {
        [$stop, $place] = $this->stopWithAddress();
        $address = $this->address($place);

        $this->saveStop(['id' => (string) $stop, 'desc' => 'Praça ENS009', 'latitude' => '-5.0912', 'longitude' => '-42.8011'])
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame($address, $this->address($place));
        $this->assertSame(['65630000', '100', 'Ao lado da escola'], $this->stopAddress($stop));
        $this->assertSame(['-5.0912', '-42.8011'], $this->stopCoordinates($stop));
    }

    public function test_put_without_the_disabled_address_fields_keeps_them(): void
    {
        [$stop, $place] = $this->stopWithAddress();
        $address = $this->address($place);

        $this->saveStop([
            'id' => (string) $stop,
            'desc' => 'Praça ENS009',
            'postal_code' => '65630-000',
            'city_id' => (string) $address['city_id'],
            'latitude' => '-5.0912',
            'longitude' => '-42.8011',
        ])->assertJsonPath('any_error_msg', false);

        $this->assertSame($address, $this->address($place));
        $this->assertSame(['65630000', '100', 'Ao lado da escola'], $this->stopAddress($stop));
    }

    public function test_put_with_the_address_fields_saves_what_was_sent(): void
    {
        [$stop, $place] = $this->stopWithAddress();
        $city = CityFactory::new()->create()->getKey();

        $this->saveStop([
            'id' => (string) $stop,
            'desc' => 'Praça ENS009',
            'postal_code' => '65631-111',
            'address' => 'Rua Nova',
            'number' => '7',
            'complement' => '',
            'neighborhood' => 'Formosa',
            'city_id' => (string) $city,
            'latitude' => '-5.0912',
            'longitude' => '-42.8011',
        ])->assertJsonPath('any_error_msg', false);

        $address = $this->address($place);

        $this->assertSame(['Rua Nova', '7', 'Formosa', $city, '65631111'], [$address['address'], $address['number'], $address['neighborhood'], $address['city_id'], $address['postal_code']]);
        $this->assertEmpty($address['complement']);
    }

    private function stopWithAddress(): array
    {
        $place = Place::query()->create([
            'city_id' => CityFactory::new()->create()->getKey(),
            'address' => 'Rua Principal',
            'number' => '100',
            'complement' => 'Ao lado da escola',
            'neighborhood' => 'Centro',
            'postal_code' => '65630000',
        ])->getKey();

        $stop = $this->stopRecord('Praça ENS009', [
            'cep' => 65630000,
            'idlog' => $place,
            'idbai' => $place,
            'numero' => 100,
            'complemento' => 'Ao lado da escola',
            'latitude' => '-5.1',
            'longitude' => '-42.1',
        ]);

        return [$stop, $place];
    }

    private function saveStop(array $params): TestResponse
    {
        return $this->postResource(self::STOP_API, array_merge(['oper' => 'put', 'resource' => 'ponto'], $params));
    }

    private function address(int $place): array
    {
        return Place::query()->findOrFail($place)->only(self::ADDRESS_COLUMNS);
    }

    private function stopAddress(int $stop): array
    {
        $record = DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->first(['cep', 'numero', 'complemento']);

        return [(string) $record->cep, (string) $record->numero, $record->complemento];
    }
}
