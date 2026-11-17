<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use App\Models\Place;
use clsModulesPontoTransporteEscolar;
use Database\Factories\CityFactory;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function emptyAddressFields(): array
    {
        return [
            'address fields not sent' => [[]],
            'address fields sent empty' => [array_fill_keys(self::ADDRESS_COLUMNS, '')],
        ];
    }

    #[DataProvider('emptyAddressFields')]
    public function test_post_with_only_the_description_and_the_location_saves_the_stop_without_address(array $addressFields): void
    {
        $places = Place::query()->count();

        $response = $this->createStop(array_merge(['desc' => 'Ponto sem endereço ENS013', 'latitude' => '-5.0912', 'longitude' => '-42.8011'], $addressFields))
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $stop = (int) $response->json('id');

        $this->assertSame('Ponto sem endereço ENS013', DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->value('descricao'));
        $this->assertSame([null, null, null], $this->stopPlaceColumns($stop));
        $this->assertSame(['-5.0912', '-42.8011'], $this->stopCoordinates($stop));
        $this->assertSame($places, Place::query()->count());
    }

    public static function addressFieldWithoutCity(): array
    {
        return [
            'postal code' => [['postal_code' => '65630-000']],
            'address' => [['address' => 'Rua da Praça']],
            'number' => [['number' => '10']],
            'complement' => [['complement' => 'Ao lado da escola']],
            'neighborhood' => [['neighborhood' => 'Centro']],
        ];
    }

    #[DataProvider('addressFieldWithoutCity')]
    public function test_post_with_an_address_field_and_no_city_saves_nothing(array $addressField): void
    {
        $stops = DB::table('modules.ponto_transporte_escolar')->count();
        $places = Place::query()->count();

        $response = $this->createStop(array_merge(
            ['desc' => 'Ponto sem município ENS013'],
            array_fill_keys(self::ADDRESS_COLUMNS, ''),
            $addressField
        ))->assertSuccessful()
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonMissingPath('id');

        $this->assertSame([clsModulesPontoTransporteEscolar::CITY_REQUIRED_MESSAGE], array_column($response->json('msgs'), 'msg'));
        $this->assertSame($stops, DB::table('modules.ponto_transporte_escolar')->count());
        $this->assertSame($places, Place::query()->count());
    }

    public function test_post_with_the_full_address_saves_it(): void
    {
        $city = CityFactory::new()->create()->getKey();

        $response = $this->createStop([
            'desc' => 'Ponto com endereço ENS013',
            'postal_code' => '65630-000',
            'address' => 'Rua da Praça',
            'number' => '10',
            'complement' => '',
            'neighborhood' => 'Centro',
            'city_id' => (string) $city,
        ])->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $stop = (int) $response->json('id');
        [$cep, $place] = $this->stopPlaceColumns($stop);
        $address = $this->address((int) $place);

        $this->assertSame('65630000', (string) $cep);
        $this->assertSame(['Rua da Praça', '10', 'Centro', $city, '65630000'], [$address['address'], $address['number'], $address['neighborhood'], $address['city_id'], $address['postal_code']]);
    }

    public function test_put_with_an_address_field_and_the_city_cleared_changes_nothing(): void
    {
        [$stop, $place] = $this->stopWithAddress();
        $address = $this->address($place);

        $response = $this->saveStop(['id' => (string) $stop, 'desc' => 'Praça alterada ENS013', 'neighborhood' => 'Formosa', 'city_id' => ''])
            ->assertJsonPath('any_error_msg', true);

        $this->assertSame([clsModulesPontoTransporteEscolar::CITY_REQUIRED_MESSAGE], array_column($response->json('msgs'), 'msg'));
        $this->assertSame($address, $this->address($place));
        $this->assertSame('Praça ENS009', DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->value('descricao'));
    }

    public function test_put_of_a_stop_without_address_keeps_it_without_address(): void
    {
        $stop = $this->stopRecord('Ponto sem endereço ENS013');
        $places = Place::query()->count();

        $this->saveStop(array_merge(['id' => (string) $stop, 'desc' => 'Ponto alterado ENS013'], array_fill_keys(self::ADDRESS_COLUMNS, '')))
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame('Ponto alterado ENS013', DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->value('descricao'));
        $this->assertSame([null, null, null], $this->stopPlaceColumns($stop));
        $this->assertSame($places, Place::query()->count());
    }

    public function test_put_of_a_stop_without_address_saves_the_address_sent(): void
    {
        $stop = $this->stopRecord('Ponto sem endereço ENS013');
        $city = CityFactory::new()->create()->getKey();

        $this->saveStop(['id' => (string) $stop, 'desc' => 'Ponto sem endereço ENS013', 'neighborhood' => 'Formosa', 'city_id' => (string) $city])
            ->assertJsonPath('any_error_msg', false);

        [, $place] = $this->stopPlaceColumns($stop);
        $address = $this->address((int) $place);

        $this->assertSame(['Formosa', $city], [$address['neighborhood'], $address['city_id']]);
    }

    private function createStop(array $params): TestResponse
    {
        return $this->postResource(self::STOP_API, array_merge(['oper' => 'post', 'resource' => 'ponto'], $params));
    }

    private function stopPlaceColumns(int $stop): array
    {
        $record = DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->first(['cep', 'idlog', 'idbai']);

        return [$record->cep, $record->idlog, $record->idbai];
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
